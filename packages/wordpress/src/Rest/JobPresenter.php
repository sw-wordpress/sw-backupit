<?php
/**
 * REST representation of jobs (docs/rest-api.md).
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Rest;

use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Core\Job\Job;

final class JobPresenter {

	/**
	 * @return array<string, mixed>
	 */
	public static function to_rest( Job $job ): array {
		$progress = $job->progress;
		$end      = $job->finishedAt ?? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$elapsed  = null === $job->startedAt ? 0 : max( 0, $end->getTimestamp() - $job->startedAt->getTimestamp() );
		$percent  = $progress->percent( $job->step, JobStatus::Completed === $job->status );
		$eta      = ( $percent > 2 && $percent < 100 && ! $job->status->isTerminal() ) ? (int) round( $elapsed * ( 100 - $percent ) / $percent ) : null;
		$format   = static fn ( ?\DateTimeImmutable $date ): ?string => $date?->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );

		return array(
			'id'            => $job->id,
			'profile_id'    => $job->profileId,
			'profile_name'  => $job->profileName(),
			'type'          => $job->type->value,
			'trigger'       => $job->trigger,
			'status'        => $job->status->value,
			'phase'         => $job->step?->value,
			'started_at'    => $format( $job->startedAt ),
			'updated_at'    => $format( $job->updatedAt ),
			'finished_at'   => $format( $job->finishedAt ),
			'progress'      => array(
				'percent'           => $percent,
				'files_total'       => $progress->filesTotal,
				'files_done'        => $progress->filesDone,
				'bytes_total'       => $progress->bytesTotal,
				'bytes_done'        => $progress->bytesDone,
				'bytes_compressed'  => $progress->bytesCompressed,
				'compression_ratio' => $progress->compressionRatio(),
				'current_item'      => $progress->currentItem,
				'volumes_done'      => $progress->volumesDone,
				'elapsed_seconds'   => $elapsed,
				'eta_seconds'       => $eta,
				'upload'            => array(
					'bytes_done'  => $progress->uploadBytesDone,
					'bytes_total' => $progress->uploadBytesTotal,
					'rate_bps'    => $progress->uploadRateBps,
				),
				'targets'           => $progress->targets,
			),
			'error'         => $job->error,
			'warnings_count' => $job->warningsCount,
			'retry_count'   => $job->retryCount,
			'next_retry_at' => $format( $job->nextRetryAt ),
			'backup_id'     => JobStatus::Completed === $job->status ? $job->backupId : null,
		);
	}
}
