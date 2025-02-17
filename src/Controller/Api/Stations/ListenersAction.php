<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations;

use App\Entity;
use App\Enums\SupportedLocales;
use App\Environment;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Service\DeviceDetector;
use App\Service\IpGeolocation;
use App\Utilities\File;
use Azura\DoctrineBatchUtils\ReadOnlyBatchIteratorAggregate;
use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Writer;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[
    OA\Get(
        path: '/station/{station_id}/listeners',
        operationId: 'getStationListeners',
        description: 'Return detailed information about current listeners.',
        security: OpenApi::API_KEY_SECURITY,
        tags: ['Stations: Listeners'],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Success',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/Api_Listener')
                )
            ),
            new OA\Response(ref: OpenApi::REF_RESPONSE_ACCESS_DENIED, response: 403),
            new OA\Response(ref: OpenApi::REF_RESPONSE_NOT_FOUND, response: 404),
            new OA\Response(ref: OpenApi::REF_RESPONSE_GENERIC_ERROR, response: 500),
        ]
    )
]
class ListenersAction
{
    public function __invoke(
        ServerRequest $request,
        Response $response,
        EntityManagerInterface $em,
        Entity\Repository\StationMountRepository $mountRepo,
        Entity\Repository\StationRemoteRepository $remoteRepo,
        IpGeolocation $geoLite,
        DeviceDetector $deviceDetector,
        Environment $environment
    ): ResponseInterface {
        set_time_limit($environment->getSyncLongExecutionTime());

        $station = $request->getStation();
        $stationTz = $station->getTimezoneObject();

        $params = $request->getQueryParams();

        $isLive = empty($params['start']);
        $now = CarbonImmutable::now($stationTz);

        $qb = $em->createQueryBuilder()
            ->select('l')
            ->from(Entity\Listener::class, 'l')
            ->where('l.station = :station')
            ->setParameter('station', $station)
            ->orderBy('l.timestamp_start', 'ASC');

        if ($isLive) {
            $range = 'live';
            $startTimestamp = $now->getTimestamp();
            $endTimestamp = $now->getTimestamp();

            $qb = $qb->andWhere('l.timestamp_end = 0');
        } else {
            $start = CarbonImmutable::parse($params['start'], $stationTz)
                ->setSecond(0);
            $startTimestamp = $start->getTimestamp();

            $end = CarbonImmutable::parse($params['end'] ?? $params['start'], $stationTz)
                ->setSecond(59);
            $endTimestamp = $end->getTimestamp();

            $range = $start->format('Y-m-d_H-i-s') . '_to_' . $end->format('Y-m-d_H-i-s');

            $qb = $qb->andWhere('l.timestamp_start < :time_end')
                ->andWhere('(l.timestamp_end = 0 OR l.timestamp_end > :time_start)')
                ->setParameter('time_start', $startTimestamp)
                ->setParameter('time_end', $endTimestamp);
        }

        $locale = $request->getAttribute(ServerRequest::ATTR_LOCALE)
            ?? SupportedLocales::default();

        $mountNames = $mountRepo->getDisplayNames($station);
        $remoteNames = $remoteRepo->getDisplayNames($station);

        $listenersIterator = ReadOnlyBatchIteratorAggregate::fromQuery($qb->getQuery(), 250);

        /** @var Entity\Api\Listener[] $listeners */
        $listeners = [];
        $listenersByHash = [];

        $groupByUnique = ('false' !== ($params['unique'] ?? 'true'));

        foreach ($listenersIterator as $listener) {
            /** @var Entity\Listener $listener */
            $listenerStart = $listener->getTimestampStart();

            if ($isLive) {
                $listenerEnd = $now->getTimestamp();
            } else {
                if ($listenerStart < $startTimestamp) {
                    $listenerStart = $startTimestamp;
                }

                $listenerEnd = $listener->getTimestampEnd();
                if (0 === $listenerEnd || $listenerEnd > $endTimestamp) {
                    $listenerEnd = $endTimestamp;
                }
            }

            $hash = $listener->getListenerHash();
            if ($groupByUnique && isset($listenersByHash[$hash])) {
                $listenersByHash[$hash]['intervals'][] = [
                    'start' => $listenerStart,
                    'end' => $listenerEnd,
                ];
                continue;
            }

            $userAgent = $listener->getListenerUserAgent();
            $dd = $deviceDetector->parse($userAgent);

            $api = new Entity\Api\Listener();
            $api->ip = $listener->getListenerIp();
            $api->user_agent = $userAgent;
            $api->hash = $hash;
            $api->client = $dd->getClient() ?? 'Unknown';
            $api->is_mobile = $dd->isMobile();

            if ($listener->getMountId()) {
                $mountId = $listener->getMountId();

                $api->mount_is_local = true;
                $api->mount_name = $mountNames[$mountId];
            } elseif ($listener->getRemoteId()) {
                $remoteId = $listener->getRemoteId();

                $api->mount_is_local = false;
                $api->mount_name = $remoteNames[$remoteId];
            }

            $api->location = $geoLite->getLocationInfo($api->ip, $locale);

            if ($groupByUnique) {
                $listenersByHash[$hash] = [
                    'api' => $api,
                    'intervals' => [
                        [
                            'start' => $listenerStart,
                            'end' => $listenerEnd,
                        ],
                    ],
                ];
            } else {
                $api->connected_on = $listenerStart;
                $api->connected_until = $listenerEnd;
                $api->connected_time = $listenerEnd - $listenerStart;
                $listeners[] = $api;
            }
        }

        if ($groupByUnique) {
            foreach ($listenersByHash as $listenerInfo) {
                $intervals = (array)$listenerInfo['intervals'];

                $startTime = $now->getTimestamp();
                $endTime = 0;
                foreach ($intervals as $interval) {
                    $startTime = min($interval['start'], $startTime);
                    $endTime = max($interval['end'], $endTime);
                }

                /** @var Entity\Api\Listener $api */
                $api = $listenerInfo['api'];
                $api->connected_on = $startTime;
                $api->connected_until = $endTime;
                $api->connected_time = Entity\Listener::getListenerSeconds($intervals);

                $listeners[] = $api;
            }
        }

        $format = $params['format'] ?? 'json';

        if ('csv' === $format) {
            return $this->exportReportAsCsv(
                $response,
                $station,
                $listeners,
                $station->getShortName() . '_listeners_' . $range . '.csv'
            );
        }

        return $response->withJson($listeners);
    }

    /**
     * @param Response $response
     * @param Entity\Station $station
     * @param Entity\Api\Listener[] $listeners
     * @param string $filename
     */
    protected function exportReportAsCsv(
        Response $response,
        Entity\Station $station,
        array $listeners,
        string $filename
    ): ResponseInterface {
        $tempFile = File::generateTempPath($filename);

        $csv = Writer::createFromPath($tempFile, 'w+');

        $tz = $station->getTimezoneObject();

        $csv->insertOne(
            [
                'IP',
                'Start Time',
                'End Time',
                'Seconds Connected',
                'User Agent',
                'Client',
                'Is Mobile',
                'Mount Type',
                'Mount Name',
                'Location',
                'Country',
                'Region',
                'City',
            ]
        );

        foreach ($listeners as $listener) {
            $startTime = CarbonImmutable::createFromTimestamp($listener->connected_on, $tz);
            $endTime = CarbonImmutable::createFromTimestamp($listener->connected_until, $tz);

            $exportRow = [
                $listener->ip,
                $startTime->toIso8601String(),
                $endTime->toIso8601String(),
                $listener->connected_time,
                $listener->user_agent,
                $listener->client,
                $listener->is_mobile ? 'True' : 'False',
            ];

            if ('' === $listener->mount_name) {
                $exportRow[] = 'Unknown';
                $exportRow[] = 'Unknown';
            } else {
                $exportRow[] = ($listener->mount_is_local) ? 'Local' : 'Remote';
                $exportRow[] = $listener->mount_name;
            }

            $location = $listener->location;
            if ('success' === $location['status']) {
                $exportRow[] = $location['region'] . ', ' . $location['country'];
                $exportRow[] = $location['country'];
                $exportRow[] = $location['region'];
                $exportRow[] = $location['city'];
            } else {
                $exportRow[] = $location['message'] ?? 'N/A';
                $exportRow[] = '';
                $exportRow[] = '';
                $exportRow[] = '';
            }

            $csv->insertOne($exportRow);
        }

        return $response->withFileDownload($tempFile, $filename, 'text/csv');
    }
}
