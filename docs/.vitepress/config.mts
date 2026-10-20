import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Laravel Queue Guard',
  description: 'Static analysis for Laravel queues, Horizon and FinTech concurrency bugs',
  base: '/laravel-queue-guard/',
  themeConfig: {
    nav: [
      { text: 'Guide', link: '/getting-started' },
      { text: 'Rules', link: '/rules/' },
      { text: 'Recipes', link: '/recipes/stripe-idempotency' },
      { text: 'GitHub', link: 'https://github.com/malikad778/laravel-queue-guard' }
    ],
    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Overview', link: '/index' },
          { text: 'Installation & Setup', link: '/getting-started' },
          { text: 'Configuration', link: '/configuration' },
          { text: 'Baseline', link: '/baseline' },
          { text: 'Autofix (--fix)', link: '/autofix' }
        ]
      },
      {
        text: 'Rules',
        items: [
          { text: 'All Rules Overview', link: '/rules/' },
          { text: 'QG001: HTTP Inside Transaction', link: '/rules/QG001-http-inside-transaction' },
          { text: 'QG002: Non-Idempotent Financial Job', link: '/rules/QG002-non-idempotent-financial-job' },
          { text: 'QG003: Missing SerializesModels', link: '/rules/QG003-missing-serializes-models' },
          { text: 'QG004: Unbounded Job Timeout', link: '/rules/QG004-unbounded-job-timeout' },
          { text: 'QG005: Batch Without Finally', link: '/rules/QG005-batch-without-finally' },
          { text: 'QG006: Sync Dispatch In Loop', link: '/rules/QG006-sync-dispatch-in-loop' },
          { text: 'QG007: Dispatch Inside Transaction', link: '/rules/QG007-dispatch-inside-transaction' },
          { text: 'QG008: Retry After Timeout Mismatch', link: '/rules/QG008-retry-after-timeout-mismatch' },
          { text: 'QG009: Request Context In Queued Class', link: '/rules/QG009-request-context-in-queued-class' }
        ]
      },
      {
        text: 'CI Integration',
        items: [
          { text: 'GitHub Actions', link: '/ci/github-actions' },
          { text: 'GitLab CI', link: '/ci/gitlab-ci' },
          { text: 'Bitbucket Pipelines', link: '/ci/bitbucket-pipelines' }
        ]
      },
      {
        text: 'Recipes',
        items: [
          { text: 'Stripe Idempotency Keys', link: '/recipes/stripe-idempotency' },
          { text: 'Transaction afterCommit', link: '/recipes/after-commit' },
          { text: 'Horizon retry_after vs Timeout', link: '/recipes/horizon-retry-after' },
          { text: 'Batching Bulk Notifications', link: '/recipes/batching-notifications' }
        ]
      },
      {
        text: 'Contributing',
        items: [
          { text: 'Writing a Custom Rule', link: '/contributing/writing-a-rule' }
        ]
      }
    ],
    socialLinks: [
      { icon: 'github', link: 'https://github.com/malikad778/laravel-queue-guard' }
    ]
  }
})
