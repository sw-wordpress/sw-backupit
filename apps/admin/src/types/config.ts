/** Config object injected by PHP as `window.swbackupitConfig`. */
export interface SwbiConfig {
  /** REST root of the plugin namespace; always ends with `/`, e.g. `https://x/wp-json/sw-backupit/v1/`. */
  restRoot: string;
  /** `wp_rest` nonce, sent as `X-WP-Nonce`. */
  nonce: string;
  locale: string;
  version: string;
  adminUrl: string;
  capabilities: string[];
  siteUrl: string;
}

export type Capability =
  | 'swbi_view'
  | 'swbi_run'
  | 'swbi_manage_profiles'
  | 'swbi_manage_storage'
  | 'swbi_download'
  | 'swbi_delete'
  | 'swbi_restore'
  | 'swbi_settings';
