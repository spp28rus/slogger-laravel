<?php

namespace SLoggerLaravel\Watchers\EntryPoints;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Queue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use SLoggerLaravel\Enums\SLoggerTraceStatusEnum;
use SLoggerLaravel\Enums\SLoggerTraceTypeEnum;
use SLoggerLaravel\Helpers\SLoggerDataFormatter;
use SLoggerLaravel\Helpers\SLoggerTraceHelper;
use SLoggerLaravel\Watchers\AbstractSLoggerWatcher;

class SLoggerJobWatcher extends AbstractSLoggerWatcher
{
    protected array $jobs = [];
    protected array $exceptedJobs = [];

    protected function init(): void
    {
        $this->exceptedJobs = $this->loggerConfig->jobsExcepted();
    }

    public function register(): void
    {
        Queue::createPayloadUsing(function ($connection, $queue, $payload) {
            return [
                'slogger_uuid'            => Str::uuid()->toString(),
                'slogger_parent_trace_id' => $this->traceIdContainer->getParentTraceId(),
            ];
        });

        $this->listenEvent(JobProcessing::class, [$this, 'handleJobProcessing']);
        $this->listenEvent(JobProcessed::class, [$this, 'handleJobProcessed']);
        $this->listenEvent(JobFailed::class, [$this, 'handleJobFailed']);
        $this->listenEvent(JobReleasedAfterException::class, [$this, 'handleJobReleasedAfterException']);
    }

    public function handleJobProcessing(JobProcessing $event): void
    {
        $this->safeHandleWatching(fn() => $this->onHandleJobProcessing($event));
    }

    protected function onHandleJobProcessing(JobProcessing $event): void
    {
        $payload = $event->job->payload();

        $jobClass = $payload['displayName'] ?? null;

        if (in_array($jobClass, $this->exceptedJobs)) {
            return;
        }

        $uuid = $payload['slogger_uuid'] ?? null;

        if (!$uuid) {
            return;
        }

        $parentTraceId = $payload['slogger_parent_trace_id'] ?? null;

        $traceId = $this->processor->startAndGetTraceId(
            type: SLoggerTraceTypeEnum::Job->value,
            tags: [
                $jobClass,
            ],
            customParentTraceId: $parentTraceId,
        );

        $this->jobs[$uuid] = [
            'trace_id'   => $traceId,
            'started_at' => now(),
        ];
    }

    public function handleJobProcessed(JobProcessed $event): void
    {
        $this->safeHandleWatching(fn() => $this->onHandleJobProcessed($event));
    }

    protected function onHandleJobProcessed(JobProcessed $event): void
    {
        $payload = $event->job->payload();

        $uuid = $payload['slogger_uuid'] ?? null;

        if (!$uuid) {
            return;
        }

        $jobData = $this->jobs[$uuid] ?? null;

        if (!$jobData) {
            return;
        }

        $traceId = $jobData['trace_id'];

        /** @var Carbon $startedAt */
        $startedAt = $jobData['started_at'];

        $data = [
            'connection_name' => $event->connectionName,
            'payload'         => $event->job->payload(),
            'status'          => 'processed',
        ];

        $this->processor->stop(
            traceId: $traceId,
            status: SLoggerTraceStatusEnum::Success->value,
            data: $data,
            duration: SLoggerTraceHelper::calcDuration($startedAt)
        );

        unset($this->jobs[$uuid]);
    }

    public function handleJobFailed(JobFailed $event): void
    {
        $this->safeHandleWatching(fn() => $this->onHandleJobFailed($event));
    }

    protected function onHandleJobFailed(JobFailed $event): void
    {
        $payload = $event->job->payload();

        $uuid = $payload['slogger_uuid'] ?? null;

        if (!$uuid) {
            return;
        }

        $jobData = $this->jobs[$uuid] ?? null;

        if (!$jobData) {
            return;
        }

        $traceId = $jobData['trace_id'];

        /** @var Carbon $startedAt */
        $startedAt = $jobData['started_at'];

        $data = [
            'connectionName' => $event->connectionName,
            'payload'        => $event->job->payload(),
            'status'         => 'failed',
            'exception'      => SLoggerDataFormatter::exception($event->exception),
        ];

        $this->processor->stop(
            traceId: $traceId,
            status: SLoggerTraceStatusEnum::Failed->value,
            data: $data,
            duration: SLoggerTraceHelper::calcDuration($startedAt)
        );

        unset($this->jobs[$uuid]);
    }

    public function handleJobReleasedAfterException(JobReleasedAfterException $event): void
    {
        $this->safeHandleWatching(fn() => $this->onHandleJobReleasedAfterException($event));
    }

    protected function onHandleJobReleasedAfterException(JobReleasedAfterException $event): void
    {
        $payload = $event->job->payload();

        $uuid = $payload['slogger_uuid'] ?? null;

        if (!$uuid) {
            return;
        }

        $jobData = $this->jobs[$uuid] ?? null;

        if (!$jobData) {
            return;
        }

        $traceId = $jobData['trace_id'];

        /** @var Carbon $startedAt */
        $startedAt = $jobData['started_at'];

        $data = [
            'connectionName' => $event->connectionName,
            'payload'        => $event->job->payload(),
            'status'         => 'released_after_exception',
        ];

        $this->processor->stop(
            traceId: $traceId,
            status: SLoggerTraceStatusEnum::Failed->value,
            data: $data,
            duration: SLoggerTraceHelper::calcDuration($startedAt)
        );

        unset($this->jobs[$uuid]);
    }
}
