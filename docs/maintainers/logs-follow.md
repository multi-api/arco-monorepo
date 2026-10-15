Decision: LIVE_TAIL

# `arco logs --follow`: Live Tail or polling

- Date: 2026-10-15
- AWS SDK for PHP: 3.401.1
- Spike: `tests/Unit/Logs/LiveTailSpikeTest.php` (milestone 2, Task 1)

## Evidence

- Recorded session parsed into sessionStart / sessionUpdate (2 events) / heartbeat: PASS
- First event yielded before the stream ends (incremental reading): PASS
- Short reads (3-byte chunks) parsed through FullReadStream: PASS
- Short reads without FullReadStream: FAIL with "Message checksum mismatch" (the wrapper is required)
- SessionTimeoutException surfaces as EventStreamDataException: PASS
- Live check against AWS: NOT RUN (pending a controller-run check, ruling R14)

## Consequences

- LIVE_TAIL: `arco logs --follow` uses StartLiveTail through `FullReadStream::wrap()` on the existing log
  groups and falls back to polling FilterLogEvents every 2 s on AccessDenied, LimitExceeded,
  InvalidOperation or ResourceNotFound.
