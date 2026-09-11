/**
 * Chat behaviour (blueprint §15, Owner Addendum A).
 *
 * Streaming is done HERE rather than in Livewire: a server round trip per
 * token would be thousands of requests for one reply. Livewire owns which
 * conversation is open and what is in it; this owns the live text.
 *
 * Everything mobile in Addendum A lands in this file — visualViewport keyboard
 * tracking, the auto-growing textarea, conditional scroll anchoring, and the
 * conversation list as a bottom sheet.
 */
export default function registerChat() {
  document.addEventListener('alpine:init', () => {
    window.Alpine.data('azivChat', (config = {}) => ({
      streaming: config.streaming !== false,
      sheetOpen: false,
      streamingNow: false,
      /** Is the reader at the bottom? Decides whether new text scrolls. */
      pinned: true,
      controller: null,
      currentUuid: null,

      init() {
        this.observeAnchor();
        this.trackKeyboard();
        this.$nextTick(() => {
          this.autoGrow();
          this.scrollToBottom(true);
        });
      },

      /* --- scroll anchoring ------------------------------------------------
       * An IntersectionObserver on a sentinel at the end of the thread, not
       * scrollTop arithmetic: the thread grows while text streams in, so any
       * number computed a moment ago is already stale.
       *
       * The rule Addendum A's gate checks: scrolling back DURING a stream must
       * not yank the view to the bottom. So new text scrolls only while the
       * reader is already at the end.
       * ------------------------------------------------------------------ */
      observeAnchor() {
        const anchor = this.$refs.anchor;
        if (!anchor || !('IntersectionObserver' in window)) return;

        new IntersectionObserver(
          (entries) => { this.pinned = entries[0].isIntersecting; },
          { root: this.$refs.thread, threshold: 0.01 },
        ).observe(anchor);
      },

      onScroll() {
        // The observer is the source of truth; this exists so the jump button
        // appears immediately on a browser without IntersectionObserver.
        if ('IntersectionObserver' in window) return;

        const el = this.$refs.thread;
        if (!el) return;
        this.pinned = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
      },

      scrollToBottom(force = false) {
        if (!force && !this.pinned) return;
        const el = this.$refs.thread;
        if (!el) return;
        el.scrollTop = el.scrollHeight;
        this.pinned = true;
      },

      /* --- the mobile keyboard --------------------------------------------
       * On iOS the visual viewport shrinks when the keyboard opens but the
       * layout viewport does not, so a bottom-anchored composer ends up
       * underneath the keyboard. visualViewport reports the real visible area;
       * the offset is published as a custom property the stylesheet uses.
       * ------------------------------------------------------------------ */
      trackKeyboard() {
        const vv = window.visualViewport;
        if (!vv) return;

        const apply = () => {
          const inset = Math.max(0, window.innerHeight - vv.height - vv.offsetTop);
          document.documentElement.style.setProperty('--keyboard-inset', `${inset}px`);
          if (inset > 0) this.scrollToBottom(true);
        };

        vv.addEventListener('resize', apply);
        vv.addEventListener('scroll', apply);
        apply();
      },

      /* --- composer -------------------------------------------------------- */
      autoGrow() {
        const el = this.$refs.draft;
        if (!el) return;
        // Reset first, or the box can only ever grow.
        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, 200)}px`;
      },

      submitIfReady() {
        const el = this.$refs.draft;
        if (!el || el.value.trim() === '' || this.streamingNow) return;
        el.closest('form')?.requestSubmit();
      },

      /* --- the sheet -------------------------------------------------------- */
      openSheet() { this.sheetOpen = true; },
      closeSheet() { this.sheetOpen = false; },

      /* --- streaming -------------------------------------------------------- */
      async startStream(uuid) {
        this.currentUuid = uuid;
        this.streamingNow = true;
        this.pinned = true;
        await this.$nextTick();
        this.scrollToBottom(true);

        if (!this.streaming) {
          await this.completeWithoutStreaming(uuid);
          return;
        }

        try {
          await this.readStream(uuid);
        } catch (e) {
          // R-01: a host that buffers output or cuts the connection breaks
          // streaming outright. Falling back produces the same answer in one
          // response rather than leaving the customer with nothing.
          await this.completeWithoutStreaming(uuid);
        }
      },

      async readStream(uuid) {
        this.controller = new AbortController();

        const response = await fetch(`/chat/${uuid}/stream`, {
          headers: { Accept: 'text/event-stream' },
          signal: this.controller.signal,
        });

        if (!response.ok || !response.body) throw new Error('stream unavailable');

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        for (;;) {
          const { done, value } = await reader.read();
          if (done) break;

          buffer += decoder.decode(value, { stream: true });

          // Events are separated by a blank line; a read can land mid-event.
          let split;
          while ((split = buffer.indexOf('\n\n')) !== -1) {
            this.handleEvent(buffer.slice(0, split));
            buffer = buffer.slice(split + 2);
          }
        }

        this.finish();
      },

      handleEvent(raw) {
        let name = 'message';
        let data = '';

        for (const line of raw.split('\n')) {
          if (line.startsWith('event:')) name = line.slice(6).trim();
          if (line.startsWith('data:')) data += line.slice(5).trim();
        }

        if (!data) return;

        let payload;
        try { payload = JSON.parse(data); } catch { return; }

        if (name === 'delta' && payload.text) {
          this.append(payload.text);
        }

        if (name === 'error') {
          this.append(`\n\n${payload.message}: ${payload.detail}`);
          this.finish();
        }

        if (name === 'done') {
          this.finish();
        }
      },

      append(text) {
        const target = this.$refs.streamTarget;
        if (target) target.textContent += text;
        // Only while the reader is at the end — this is the behaviour the
        // responsive gate checks.
        this.scrollToBottom();
      },

      async completeWithoutStreaming(uuid) {
        try {
          const response = await fetch(`/chat/${uuid}/complete`, {
            method: 'POST',
            headers: {
              'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
              Accept: 'application/json',
            },
          });

          const payload = await response.json();
          const target = this.$refs.streamTarget;

          if (target) {
            target.textContent = response.ok
              ? (payload.content ?? '')
              : `${payload.message}: ${payload.detail}`;
          }
        } finally {
          this.finish();
        }
      },

      async stopStream() {
        if (!this.currentUuid) return;

        // Tell the server first: it settles the message and keeps the partial
        // text, which the customer has already been charged for. Aborting the
        // connection alone would leave the row in `streaming` forever.
        await fetch(`/chat/${this.currentUuid}/stop`, {
          method: 'POST',
          headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            Accept: 'application/json',
          },
        }).catch(() => {});

        this.controller?.abort();
        this.finish();
      },

      finish() {
        if (!this.streamingNow) return;
        this.streamingNow = false;
        this.controller = null;
        this.currentUuid = null;
        // Livewire re-reads the settled message from the database, so what
        // stays on screen is what was actually stored.
        this.$wire.streamFinished();
      },

      /* --- copy -------------------------------------------------------------- */
      async copyMessage(button) {
        const text = button.closest('[data-uuid]')?.querySelector('.aziv-msg-body')?.textContent ?? '';
        try {
          await navigator.clipboard.writeText(text);
          button.dataset.copied = 'true';
          setTimeout(() => delete button.dataset.copied, 1500);
        } catch { /* clipboard refused; nothing useful to say */ }
      },
    }));
  });
}
