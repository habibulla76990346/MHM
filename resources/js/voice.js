/**
 * Voice: speaking to Aziv AI and hearing it answer (§18, Owner Addendum A).
 *
 * WHY THE RECORDING STATE IS THE POINT. A microphone that might be listening
 * is the single most uncomfortable control a web application has. So the state
 * is unmistakable and is announced three ways at once: the button changes its
 * label and its `aria-pressed`, a live region says "Recording" with a running
 * timer, and the whole composer carries `is-recording` so the styling can say
 * it too. When recording ends, the track is stopped explicitly — leaving it
 * open keeps the browser's own microphone indicator lit, which tells the
 * customer they are still being listened to when they are not.
 *
 * WHAT IT NEVER DOES IS SEND. Speech recognition gets names, numbers and
 * negations wrong. The transcript lands in the composer for the customer to
 * read and correct, and pressing Send is still theirs to do.
 *
 * NOTHING HERE NAMES A PROVIDER. The endpoints are the platform's, and which
 * company transcribed the audio is a routing decision made on the server.
 */

const POLL_MS = 1200;
const POLL_LIMIT = 100;         // ~2 minutes, then it is not coming

/** MediaRecorder needs a type it can actually produce, and browsers differ. */
function pickMimeType() {
  const candidates = [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/ogg;codecs=opus',
    'audio/mp4',
  ];

  if (typeof MediaRecorder === 'undefined' || !MediaRecorder.isTypeSupported) {
    return '';
  }

  return candidates.find((type) => MediaRecorder.isTypeSupported(type)) || '';
}

function csrf() {
  return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

async function poll(url, onDone, onFail) {
  for (let attempt = 0; attempt < POLL_LIMIT; attempt++) {
    await new Promise((r) => setTimeout(r, POLL_MS));

    let state;

    try {
      const response = await fetch(url, { headers: { Accept: 'application/json' } });
      state = await response.json();
    } catch {
      continue;   // a dropped poll is not a failed job
    }

    if (state.status === 'completed') return onDone(state);
    if (state.status === 'failed') return onFail(state.error || '');
  }

  onFail('');
}

/* ---------------------------------------------------------------------------
 * Speaking: the microphone button in the composer.
 * ------------------------------------------------------------------------ */
function initRecorder(root) {
  const button = root.querySelector('[data-voice-record]');
  if (!button) return;

  const status = root.querySelector('[data-voice-status]');
  const composer = root.querySelector('.aziv-composer');
  const draft = root.querySelector('#chat-draft');
  const maxSeconds = parseInt(button.dataset.maxSeconds || '120', 10);
  const endpoint = button.dataset.endpoint;
  const idleLabel = button.dataset.idleLabel || 'Record';
  const recordingLabel = button.dataset.recordingLabel || 'Stop recording';

  let recorder = null;
  let stream = null;
  let chunks = [];
  let startedAt = 0;
  let ticker = null;

  function say(message, tone = 'info') {
    if (!status) return;
    status.textContent = message;
    status.dataset.tone = tone;
    status.hidden = message === '';
  }

  function setRecording(on) {
    button.dataset.recording = on ? 'true' : 'false';
    button.setAttribute('aria-pressed', on ? 'true' : 'false');
    button.textContent = on ? recordingLabel : idleLabel;
    composer?.classList.toggle('is-recording', on);
  }

  /**
   * Let go of the microphone.
   *
   * Explicit, and called on every path out — success, failure and the length
   * cap. A track left open keeps the browser's recording indicator lit, which
   * tells the customer they are still being listened to when they are not.
   */
  function release() {
    clearInterval(ticker);
    ticker = null;
    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
    recorder = null;
    setRecording(false);
  }

  async function start() {
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
      say(button.dataset.unsupportedMessage || 'This browser cannot record audio.', 'danger');
      return;
    }

    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch {
      // Denied, or no microphone. Both are the customer's own decision to
      // reverse, so the wording points at the browser rather than at us.
      say(button.dataset.deniedMessage || 'Aziv AI needs permission to use your microphone.', 'danger');
      return;
    }

    const mimeType = pickMimeType();
    chunks = [];
    recorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined);

    recorder.addEventListener('dataavailable', (event) => {
      if (event.data && event.data.size > 0) chunks.push(event.data);
    });

    recorder.addEventListener('stop', () => {
      const seconds = Math.max(0.3, (Date.now() - startedAt) / 1000);
      const blob = new Blob(chunks, { type: recorder?.mimeType || mimeType || 'audio/webm' });
      release();
      send(blob, seconds);
    });

    startedAt = Date.now();
    recorder.start();
    setRecording(true);

    // A visible timer, because the only thing worse than a microphone that
    // might be on is one that has been on for longer than you think.
    ticker = setInterval(() => {
      const elapsed = Math.floor((Date.now() - startedAt) / 1000);
      say(`${button.dataset.recordingMessage || 'Recording'} ${elapsed}s / ${maxSeconds}s`, 'recording');

      if (elapsed >= maxSeconds) stop();
    }, 250);

    say(`${button.dataset.recordingMessage || 'Recording'} 0s / ${maxSeconds}s`, 'recording');
  }

  function stop() {
    if (recorder && recorder.state !== 'inactive') {
      recorder.stop();
    } else {
      release();
    }
  }

  async function send(blob, seconds) {
    say(button.dataset.sendingMessage || 'Transcribing…', 'info');

    const body = new FormData();
    body.append('audio', blob, 'recording.webm');
    body.append('seconds', seconds.toFixed(2));

    let started;

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
        body,
      });
      started = await response.json();

      if (!response.ok) {
        // The server's refusals are sentences the product wrote, so they are
        // safe to show exactly as they arrive.
        say(started.error || button.dataset.failedMessage || 'That did not work.', 'danger');
        return;
      }
    } catch {
      say(button.dataset.failedMessage || 'That did not work.', 'danger');
      return;
    }

    poll(
      `${button.dataset.pollBase}/${started.uuid}`,
      (state) => {
        say('', 'info');
        fill(state.text || '');
      },
      (message) => say(message || button.dataset.failedMessage || 'That did not work.', 'danger'),
    );
  }

  /**
   * Put the transcript where the customer can read it — and NOT send it.
   *
   * `input` is dispatched so Livewire's binding sees the change; setting
   * `.value` alone leaves the server holding the old draft and the message
   * sends empty.
   */
  function fill(text) {
    if (!draft || !text) return;

    const existing = draft.value.trim();
    draft.value = existing ? `${existing} ${text}` : text;
    draft.dispatchEvent(new Event('input', { bubbles: true }));
    draft.focus();
  }

  button.addEventListener('click', () => {
    if (button.dataset.recording === 'true') stop();
    else start();
  });

  // A page hidden mid-recording — a tab switch, a phone locking — releases the
  // microphone rather than recording whatever happens next.
  document.addEventListener('visibilitychange', () => {
    if (document.hidden && button.dataset.recording === 'true') stop();
  });

  setRecording(false);
}

/* ---------------------------------------------------------------------------
 * Listening: the play button on a reply.
 * ------------------------------------------------------------------------ */
function initPlayback(root) {
  const player = new Audio();

  root.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-voice-play]');
    if (!button) return;

    event.preventDefault();

    if (button.dataset.busy === 'true') return;

    const ready = button.dataset.audio;

    if (ready) {
      player.src = ready;
      player.play().catch(() => {});
      return;
    }

    button.dataset.busy = 'true';
    const original = button.textContent;
    button.textContent = button.dataset.preparingLabel || 'Preparing…';

    const finish = (state) => {
      button.dataset.busy = 'false';
      button.textContent = original;

      if (state?.audio) {
        // Remembered, so a second press plays rather than paying twice.
        button.dataset.audio = state.audio;
        player.src = state.audio;
        player.play().catch(() => {});
      }
    };

    try {
      const response = await fetch(button.dataset.endpoint, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
      });
      const started = await response.json();

      if (!response.ok) {
        button.dataset.busy = 'false';
        button.textContent = original;
        return;
      }

      if (started.status === 'completed' && started.audio) {
        finish(started);
        return;
      }

      poll(`${button.dataset.pollBase}/${started.uuid}`, finish, () => finish(null));
    } catch {
      finish(null);
    }
  });
}

export function initVoice(root = document) {
  initRecorder(root);
  initPlayback(root);
}
