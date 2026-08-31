<?php

namespace App\ML;

/**
 * Append-only, privacy-preserving feedback queue. A correction is evidence
 * for a future candidate model; it never changes production weights inside a
 * web request.
 */
final class FeedbackStore
{
    public function __construct(private string $filePath)
    {
        $directory = dirname($this->filePath);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
    }

    /** @return array{accepted: bool, duplicate: bool, queue_size: int, event_id: string} */
    public function enqueue(
        float $distanceKm,
        int $stops,
        string $correctLabel,
        string $predictedLabel,
        string $modelVersion,
        string $eventId
    ): array {
        $safeEventId = hash('sha256', trim($eventId));
        $event = [
            'event_id' => $safeEventId,
            'distance_km' => round(max(0.0, min(1500.0, $distanceKm)), 2),
            'stops' => max(2, min(12, $stops)),
            'correct_label' => $correctLabel,
            'predicted_label' => $predictedLabel,
            'model_version' => preg_replace('/[^a-zA-Z0-9._-]/', '', $modelVersion) ?: 'unknown',
            'created_at' => gmdate('c'),
        ];

        $fp = fopen($this->filePath, 'c+');
        if ($fp === false) {
            throw new \RuntimeException('Could not open the feedback queue.');
        }

        flock($fp, LOCK_EX);
        rewind($fp);
        $raw = stream_get_contents($fp);
        $lines = array_values(array_filter(preg_split('/\R/', (string) $raw) ?: []));
        foreach ($lines as $line) {
            $existing = json_decode($line, true);
            if (is_array($existing) && ($existing['event_id'] ?? null) === $safeEventId) {
                flock($fp, LOCK_UN);
                fclose($fp);

                return [
                    'accepted' => true,
                    'duplicate' => true,
                    'queue_size' => count($lines),
                    'event_id' => $safeEventId,
                ];
            }
        }

        fseek($fp, 0, SEEK_END);
        $encoded = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || fwrite($fp, $encoded . "\n") === false) {
            flock($fp, LOCK_UN);
            fclose($fp);
            throw new \RuntimeException('Could not append to the feedback queue.');
        }
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return [
            'accepted' => true,
            'duplicate' => false,
            'queue_size' => count($lines) + 1,
            'event_id' => $safeEventId,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function events(): array
    {
        if (!is_file($this->filePath)) {
            return [];
        }
        $events = [];
        foreach (file($this->filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $event = json_decode($line, true);
            if (is_array($event)) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * Moves only reviewed events into an immutable archive and leaves every
     * pending/unapproved event in the active queue.
     *
     * @param string[] $eventIds Hashed event IDs as stored in the queue.
     * @return array{archived: int, remaining: int}
     */
    public function archiveSelected(array $eventIds, string $archivePath): array
    {
        $selected = array_fill_keys($eventIds, true);
        $fp = fopen($this->filePath, 'c+');
        if ($fp === false) {
            throw new \RuntimeException('Could not open the feedback queue.');
        }

        flock($fp, LOCK_EX);
        rewind($fp);
        $lines = array_values(array_filter(preg_split('/\R/', (string) stream_get_contents($fp)) ?: []));
        $archived = [];
        $remaining = [];
        foreach ($lines as $line) {
            $event = json_decode($line, true);
            $eventId = is_array($event) && is_string($event['event_id'] ?? null) ? $event['event_id'] : '';
            if ($eventId !== '' && isset($selected[$eventId])) {
                $archived[] = $line;
            } else {
                $remaining[] = $line;
            }
        }

        if ($archived !== []) {
            $archiveDirectory = dirname($archivePath);
            if (!is_dir($archiveDirectory)) {
                @mkdir($archiveDirectory, 0775, true);
            }
            $archivePayload = implode("\n", $archived) . "\n";
            if (file_put_contents($archivePath, $archivePayload, LOCK_EX) === false) {
                flock($fp, LOCK_UN);
                fclose($fp);
                throw new \RuntimeException('Could not archive reviewed feedback.');
            }
        }

        ftruncate($fp, 0);
        rewind($fp);
        if ($remaining !== [] && fwrite($fp, implode("\n", $remaining) . "\n") === false) {
            flock($fp, LOCK_UN);
            fclose($fp);
            throw new \RuntimeException('Could not update the feedback queue.');
        }
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return ['archived' => count($archived), 'remaining' => count($remaining)];
    }
}
