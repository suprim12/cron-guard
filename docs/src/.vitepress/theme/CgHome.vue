<script setup>
import { computed, ref } from 'vue'
import { withBase } from 'vitepress'

// ---------------------------------------------------------------------------
// Hero: a site's scheduled commands. Each mishap changes what Cron Guard shows and sends.
// ---------------------------------------------------------------------------

const COMMANDS = [
  { name: 'queue/run', every: 'every minute', last: '20 seconds ago', took: '4 seconds' },
  { name: 'feed-me/feeds/queue --id=3', every: 'every day', last: '6 hours ago', took: '42 seconds' },
  { name: 'db/backup', every: 'every day', last: '19 hours ago', took: '1.6 minutes' },
  { name: 'gc', every: 'every day', last: '3 hours ago', took: '12 seconds' },
]

const MISHAPS = [
  {
    id: 'feed',
    label: 'The supplier’s feed times out',
    note: 'An exception',
    changes: { 'feed-me/feeds/queue --id=3': ['failing', 'failed with exit code 1: cURL error 28: Operation timed out after 30001 ms'] },
    subject: 'feed-me/feeds/queue --id=3 failed on Green Grocer',
    when: 'As the run ends',
  },
  {
    id: 'memory',
    label: 'The backup runs out of memory',
    note: 'Killed mid-run',
    changes: { 'db/backup': ['stuck', 'started 2.5 hours ago and hasn’t finished'] },
    subject: 'db/backup is stuck on Green Grocer',
    when: 'An hour after it started',
  },
  {
    id: 'slow',
    label: 'Garbage collection crawls',
    note: 'A table grew',
    changes: { gc: ['slow', 'took 9 minutes; it usually takes 12 seconds'] },
    subject: 'gc ran slow on Green Grocer',
    when: 'As the run ends',
  },
  {
    id: 'crontab',
    label: 'A server move loses the crontab',
    note: 'Nothing runs',
    changes: Object.fromEntries(COMMANDS.map((c) => [c.name, ['overdue', c.name === 'queue/run' ? 'hasn’t run for 26 hours; it usually runs every minute' : 'hasn’t run for 26 hours; it usually runs every day']])),
    subject: 'Cron isn’t running on Green Grocer',
    when: 'The next time anyone visits, or your uptime monitor checks',
    stopped: true,
  },
]

const picked = ref(null)
const mishap = computed(() => MISHAPS.find((m) => m.id === picked.value) ?? null)
const rows = computed(() => COMMANDS.map((c) => {
  const change = mishap.value?.changes[c.name]
  return { ...c, state: change ? change[0] : 'ok', message: change ? change[1] : '' }
}))
const problems = computed(() => rows.value.filter((r) => r.state !== 'ok'))
const word = (s) => ({ ok: 'OK', failing: 'Failed', stuck: 'Stuck', overdue: 'Missed', slow: 'Slow' }[s])

const copied = ref(false)
const installCmd = 'composer require suprim/cron-guard && php craft plugin/install cron-guard'
async function copy() {
  try {
    await navigator.clipboard.writeText(installCmd)
    copied.value = true
    setTimeout(() => (copied.value = false), 1600)
  } catch {
    /* clipboard unavailable: the text is still selectable */
  }
}
</script>

<template>
  <div class="ch">
    <!-- Hero ------------------------------------------------------------- -->
    <section class="ch-hero">
      <div class="ch-wrap"><div class="ch-hero__head" data-reveal>
        <p class="ch-kicker">Free plugin for Craft CMS 5</p>
        <h1>Know when cron stops</h1>
        <p class="ch-lead">Cron Guard records every command your server’s cron runs, learns how often each one runs, and tells you when one fails, gets stuck, runs slow or stops running. Install it and it starts watching: no config, no extra cron line. Break something below.</p>
        <p class="ch-actions">
          <a class="ch-btn" :href="withBase('/getting-started/installation')">Get started</a>
          <a class="ch-link" :href="withBase('/guides/how-it-works')">How it works →</a>
        </p>
      </div></div>

      <div class="ch-wrap">
        <div class="ch-live" data-reveal>
          <div class="ch-live__acts" role="group" aria-label="Break something">
            <p class="ch-live__label">What goes wrong</p>
            <button v-for="m in MISHAPS" :key="m.id" type="button" class="ch-act" :aria-pressed="picked === m.id" @click="picked = picked === m.id ? null : m.id">
              <b>{{ m.label }}</b>
              <small>{{ m.note }}</small>
            </button>
          </div>
          <div class="ch-live__panel">
            <p class="ch-live__label">Utilities → Cron Guard</p>
            <p class="ch-head" :class="problems.length ? 'is-bad' : 'is-good'">
              {{ problems.length ? (problems.length === 1 ? 'One command needs a look.' : `${problems.length} commands need a look.`) : 'Every command is running as it should.' }}
            </p>
            <ul class="ch-rows" aria-live="polite">
              <li v-for="r in rows" :key="r.name" :class="'is-' + r.state">
                <code>{{ r.name }}</code>
                <span class="ch-state"><i aria-hidden="true"></i>{{ word(r.state) }}</span>
                <span class="ch-every">{{ r.every }}</span>
                <small v-if="r.message" class="ch-msg">{{ r.message }}</small>
              </li>
            </ul>
            <div class="ch-alert" :class="{ 'is-on': mishap }">
              <template v-if="mishap">
                <p class="ch-alert__meta">Email, Slack and webhook · {{ mishap.when }}</p>
                <p class="ch-alert__subject">{{ mishap.subject }}</p>
                <p v-if="mishap.stopped" class="ch-alert__text">None of the scheduled commands have run when they usually do, so cron itself has most likely stopped.</p>
                <p v-else class="ch-alert__text">✗ {{ problems[0].name }} {{ problems[0].message }}.</p>
              </template>
              <p v-else class="ch-alert__idle">Pick something on the left to see the alert you’d get.</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Features ------------------------------------------------------------ -->
    <section class="ch-wrap ch-features" aria-labelledby="ch-features-title">
      <h2 id="ch-features-title" data-reveal>Quiet until something’s wrong</h2>
      <div class="ch-cells">
        <article class="ch-cell" data-reveal>
          <h3>Learns each schedule</h3>
          <p>After four runs it knows a command runs every minute or every night. Commands run on deploy are recognised as irregular and never reported missing.</p>
        </article>
        <article class="ch-cell" data-reveal>
          <h3>Nothing to set up</h3>
          <p>Your crontab stays as it is. Commands typed in a terminal are ignored, so testing by hand never adds noise.</p>
        </article>
        <article class="ch-cell" data-reveal>
          <h3>One alert per problem</h3>
          <p>Sent once, again after a day if it’s still there, and “back to normal” when it’s fixed. Email, Slack, Discord, Teams or a webhook.</p>
        </article>
        <article class="ch-cell" data-reveal>
          <h3>Catches crashes</h3>
          <p>Exceptions are saved with their message. Runs killed for memory or time show as stuck, because they never finish.</p>
        </article>
        <article class="ch-cell" data-reveal>
          <h3>Notices cron stopping</h3>
          <p>When every scheduled command is late at once, you get one alert saying cron has stopped, not ten.</p>
        </article>
        <article class="ch-cell" data-reveal>
          <h3>Works with uptime monitors</h3>
          <p>A health URL answers 200 or 503, and runs the check, so alerts still go out on a site nobody visits.</p>
        </article>
      </div>
    </section>

    <section class="ch-wrap ch-shot" data-reveal>
      <img :src="withBase('/images/utility.jpg')" width="1860" height="840" alt="Utilities → Cron Guard: a feed that failed, a backup that missed its run, and commands running as they should" loading="lazy">
    </section>

    <!-- Install ------------------------------------------------------------- -->
    <section class="ch-wrap" data-reveal><div class="ch-install">
      <h2>Free, and running in a minute</h2>
      <p>Craft CMS 5.6 or later, PHP 8.2 or later. MIT licensed.</p>
      <div class="ch-cmd">
        <code>{{ installCmd }}</code>
        <button type="button" @click="copy">{{ copied ? 'Copied' : 'Copy' }}</button>
      </div>
      <p><a class="ch-link" :href="withBase('/getting-started/installation')">Then add where alerts go →</a></p>
    </div></section>
  </div>
</template>

<style scoped>
.ch { padding-bottom: 96px; }
.ch-wrap { max-width: 1152px; margin: 0 auto; padding: 0 24px; }

.ch-hero { padding: 72px 0 56px; background: radial-gradient(1200px 500px at 20% -10%, rgba(94, 234, 212, 0.16), transparent 60%); }
.ch-hero__head { max-width: 760px; margin-bottom: 40px; }
.ch-kicker { margin: 0 0 12px; font-size: 14px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--vp-c-brand-1); }
.ch-hero h1 { margin: 0; font-size: clamp(40px, 6vw, 64px); line-height: 1.05; font-weight: 700; letter-spacing: -0.02em; }
.ch-lead { margin: 20px 0 0; font-size: 19px; line-height: 1.6; color: var(--vp-c-text-2); }
.ch-actions { display: flex; flex-wrap: wrap; gap: 20px; align-items: center; margin: 28px 0 0; }
.ch-btn { display: inline-block; padding: 12px 22px; border-radius: 999px; background: var(--vp-button-brand-bg); color: var(--vp-button-brand-text); font-weight: 600; text-decoration: none; transition: background 0.2s; }
.ch-btn:hover { background: var(--vp-button-brand-hover-bg); color: var(--vp-button-brand-hover-text); }
.ch-link { font-weight: 600; color: var(--vp-c-brand-1); text-decoration: none; }
.ch-link:hover { text-decoration: underline; }

.ch-live { display: grid; grid-template-columns: minmax(0, 300px) minmax(0, 1fr); gap: 20px; }
.ch-live__label { margin: 0 0 12px; font-size: 13px; font-weight: 600; color: var(--vp-c-text-3); text-transform: uppercase; letter-spacing: 0.05em; }
.ch-live__acts { display: flex; flex-direction: column; gap: 10px; }
.ch-act { display: block; width: 100%; text-align: left; padding: 14px 16px; border: 1px solid var(--vp-c-divider); border-radius: 12px; background: var(--vp-c-bg); cursor: pointer; transition: border-color 0.2s, transform 0.2s, box-shadow 0.2s; }
.ch-act:hover { border-color: var(--vp-c-brand-1); transform: translateY(-1px); }
.ch-act[aria-pressed='true'] { border-color: var(--vp-c-brand-1); box-shadow: 0 0 0 3px var(--vp-c-brand-soft); }
.ch-act b { display: block; font-size: 15px; }
.ch-act small { display: block; margin-top: 2px; color: var(--vp-c-text-3); }

.ch-live__panel { padding: 20px; border: 1px solid var(--vp-c-divider); border-radius: 16px; background: var(--vp-c-bg); box-shadow: 0 20px 50px -30px rgba(15, 42, 39, 0.45); min-width: 0; }
.ch-head { margin: 0 0 12px; font-weight: 600; }
.ch-head.is-bad { color: var(--cg-red); }
.ch-head.is-good { color: var(--cg-green); }
.ch-rows { list-style: none; margin: 0; padding: 0; }
.ch-rows li { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; gap: 4px 16px; align-items: center; padding: 10px 0; border-top: 1px solid var(--vp-c-divider); transition: background 0.3s; }
.ch-rows code { font-size: 13px; overflow-wrap: anywhere; }
.ch-state { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; white-space: nowrap; }
.ch-state i { width: 9px; height: 9px; border-radius: 50%; background: var(--cg-green); }
.is-failing .ch-state i, .is-stuck .ch-state i, .is-overdue .ch-state i { background: var(--cg-red); }
.is-slow .ch-state i { background: var(--cg-amber); }
.ch-every { font-size: 13px; color: var(--vp-c-text-3); white-space: nowrap; }
.ch-msg { grid-column: 1 / -1; font-size: 13px; color: var(--vp-c-text-2); }

.ch-alert { margin-top: 16px; padding: 14px 16px; border-radius: 12px; background: var(--cg-mat); transition: background 0.3s; min-height: 92px; }
.ch-alert.is-on { background: var(--vp-c-brand-soft); }
.ch-alert p { margin: 0; }
.ch-alert__meta { font-size: 12px; color: var(--vp-c-text-3); }
.ch-alert__subject { margin-top: 4px !important; font-weight: 600; }
.ch-alert__text { margin-top: 4px !important; font-size: 14px; color: var(--vp-c-text-2); }
.ch-alert__idle { font-size: 14px; color: var(--vp-c-text-3); }

.ch-features { padding-top: 88px; }
.ch-features h2, .ch-install h2 { margin: 0 0 28px; font-size: 32px; line-height: 1.2; letter-spacing: -0.01em; border: 0; padding: 0; }
.ch-cells { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
.ch-cell { padding: 22px; border: 1px solid var(--vp-c-divider); border-radius: 14px; background: var(--vp-c-bg-soft); }
.ch-cell h3 { margin: 0 0 8px; font-size: 17px; }
.ch-cell p { margin: 0; font-size: 15px; line-height: 1.6; color: var(--vp-c-text-2); }

.ch-shot { padding-top: 56px; }
.ch-shot img { width: 100%; height: auto; padding: 10px; border-radius: 14px; background: var(--cg-mat); }

.ch-install { padding-top: 88px; max-width: 760px; }
.ch-install p { color: var(--vp-c-text-2); }
.ch-cmd { display: flex; gap: 8px; align-items: center; margin: 16px 0; padding: 12px 12px 12px 16px; border-radius: 12px; background: var(--cg-ink); }
.ch-cmd code { flex: 1; min-width: 0; color: #ccfbf1; background: none; font-size: 14px; overflow-x: auto; white-space: nowrap; }
.ch-cmd button { padding: 6px 14px; border-radius: 8px; background: var(--cg-brand); color: var(--cg-ink); font-weight: 600; font-size: 13px; }

@media (max-width: 860px) {
  .ch-live { grid-template-columns: 1fr; }
  .ch-cells { grid-template-columns: 1fr; }
  .ch-rows li { grid-template-columns: minmax(0, 1fr) auto; }
  .ch-every { display: none; }
}
</style>
