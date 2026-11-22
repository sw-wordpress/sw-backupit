export default {
  title: 'SW-BackupIt',
  description: 'User documentation for WordPress backup, storage and standalone recovery.',
  lang: 'en-US',
  base: '/sw-backupit/',
  srcExclude: ['README.md'],
  lastUpdated: true,
  // VitePress deliberately leaves 404's app container empty for client routing.
  // With JavaScript disabled, provide the same footer without duplicating it
  // when the custom Layout is mounted by JavaScript.
  transformHtml(html, _file, { page }) {
    if (page !== '404.md') return html;
    return html.replace('</body>', '<noscript><p><a href="/sw-backupit/">Documentation overview</a></p><footer class="swbi-docs-footer">Open source by Stefan Warnat – free to use, free to change, better together.</footer></noscript></body>');
  },
  themeConfig: {
    nav: [
      { text: 'Guide', link: '/getting-started' },
      { text: 'Changelog', link: '/Changelog' },
      { text: 'Repository', link: 'https://github.com/sw-wordpress/sw-backupit' }
    ],
    sidebar: [
      { text: 'Overview', link: '/' },
      { text: 'Getting started', link: '/getting-started' },
      { text: 'Profiles and schedules', link: '/profiles-and-schedules' },
      { text: 'Storage and encryption', link: '/storage-and-encryption' },
      { text: 'Backups and restore', link: '/backups-and-restore' },
      { text: 'Settings and troubleshooting', link: '/settings-and-troubleshooting' },
      { text: 'Changelog', link: '/Changelog' }
    ],
    search: { provider: 'local' },
    socialLinks: [{ icon: 'github', link: 'https://github.com/sw-wordpress/sw-backupit' }],
    editLink: {
      pattern: 'https://github.com/sw-wordpress/sw-backupit/edit/main/pages/:path',
      text: 'Edit this page on GitHub'
    }
  }
};
