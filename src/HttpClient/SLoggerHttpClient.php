<?php

namespace SLoggerLaravel\HttpClient;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use SLoggerLaravel\Objects\SLoggerTraceObjects;
use SLoggerLaravel\Objects\SLoggerTraceUpdateObjects;
use SLoggerLaravel\Profiling\Dto\SLoggerProfilingObjects;

class SLoggerHttpClient
{
    public function __construct(protected ClientInterface $client)
    {
    }

    /**
     * @throws GuzzleException
     */
    public function sendTraces(SLoggerTraceObjects $traceObjects): void
    {
        $traces = [];

        foreach ($traceObjects->get() as $traceObject) {
            $traces[] = [
                'trace_id'        => $traceObject->traceId,
                'parent_trace_id' => $traceObject->parentTraceId,
                'type'            => $traceObject->type,
                'status'          => $traceObject->status,
                'tags'            => $traceObject->tags,
                'data'            => json_encode($traceObject->data),
                'duration'        => $traceObject->duration,
                'memory'          => $traceObject->memory,
                'cpu'             => $traceObject->cpu,
                'logged_at'       => (float) ($traceObject->loggedAt->unix()
                    . '.' . $traceObject->loggedAt->microsecond),
            ];
        }

        $this->client->request('post', '/traces-api', [
            'json' => [
                'traces' => $traces,
            ],
        ]);
    }

    /**
     * @throws GuzzleException
     */
    public function updateTraces(SLoggerTraceUpdateObjects $traceObjects): void
    {
        $traces = [];

        foreach ($traceObjects->get() as $traceObject) {
            $traces[] = [
                'trace_id' => $traceObject->traceId,
                'status'   => $traceObject->status,
                ...(is_null($traceObject->profiling)
                    ? []
                    : ['profiling' => $this->prepareProfiling($traceObject->profiling)]),
                ...(is_null($traceObject->tags)
                    ? []
                    : ['tags' => $traceObject->tags]),
                ...(is_null($traceObject->data)
                    ? []
                    : ['data' => json_encode($traceObject->data)]),
                ...(is_null($traceObject->duration)
                    ? []
                    : ['duration' => $traceObject->duration]),
                ...(is_null($traceObject->memory)
                    ? []
                    : ['memory' => $traceObject->memory]),
                ...(is_null($traceObject->cpu)
                    ? []
                    : ['cpu' => $traceObject->cpu]),
            ];
        }

        $this->client->request('patch', '/traces-api', [
            'json' => [
                'traces' => $traces,
            ],
        ]);
    }

    private function prepareProfiling(SLoggerProfilingObjects $profiling): array
    {
        $result = [];

        foreach ($profiling->getItems() as $item) {
            $result[] = [
                'raw'      => $item->raw,
                'calling'  => $item->calling,
                'callable' => $item->callable,
                'data'     => [
                    'number_of_calls'         => $item->data->numberOfCalls,
                    'wait_time_in_ms'         => $item->data->waitTimeInMs,
                    'cpu_time'                => $item->data->cpuTime,
                    'memory_usage_in_bytes'   => $item->data->memoryUsageInBytes,
                    'peak_memory_usage_in_mb' => $item->data->peakMemoryUsageInMb,
                ],
            ];
        }

        return $result;
    }
}
