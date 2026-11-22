# Storage and encryption

## Add a storage target

1. Open **Storage targets → Add storage target**, give the target a name and choose its type. The type cannot be changed after creation.
2. Enter the connection information below. Save the target, then use **Test connection**; the test writes, reads and deletes a probe file, so read-only access is insufficient.
3. Edit the relevant **Profiles**, select the target in **Storage targets** and review **Success policy**. Adding a target alone does not assign it to any profile.
4. Run the profile and inspect the resulting backup's **Locations** and job log to confirm that the intended copy was verified.

| Type | Information to prepare |
| --- | --- |
| Local | An absolute **Directory**, preferably outside the public web root; PHP needs filesystem access. This is a directory on the current server, not your desktop. |
| SFTP | **Host**, **Port**, **Username**, **Base directory**, password or private key (and key passphrase if needed), and **Host key fingerprint** obtained through a trusted channel. |
| S3 compatible | **Bucket**, **Region**, access/secret key; optional **Endpoint**, **Prefix**, and **Use path-style addressing** if required by the provider. Leave endpoint empty for Amazon S3. |

Storage credentials are write-only in the UI and stored encrypted on the server. On edit, leave a secret field empty to keep its stored value. Do not paste credentials into screenshots, issue reports or job logs.

SFTP's **Accept unknown host key** bypasses identity verification and carries a man-in-the-middle risk; use the verified fingerprint instead. Private and loopback destinations are blocked by default. Enable **Allow access to private networks** only for a deliberate internal service, not as a generic connection-error workaround.

## Success policy and protected storage

- **All targets must succeed** (new-profile default): every selected target must confirm its copy.
- **At least one target**: one successful target is sufficient; other failures still raise warnings.
- **Local target only**: local success decides the result; remote failures do not make the local backup unusable.

Uploads are verified and the manifest is published last. A failed destination does not invalidate verified copies elsewhere. Still inspect individual target errors: a successful run under a permissive policy does not prove every off-site copy exists.

**Immutable (backups must not be deleted or overwritten)** and **Lock period** restrict cleanup and moves. S3 **Object Lock mode** additionally requires a bucket created with Object Lock enabled; otherwise uploads fail. Compliance mode cannot be lifted before expiry, even by the account owner. Do not enable it merely to test a connection. The existing tests cover Object Lock for single-part uploads, not a blanket guarantee for every provider/upload mode.

## Encrypt a profile

1. Go to **Settings → Encryption keys → Create a new key**.
2. Enter a **Key ID**. Leave **Passphrase (optional)** empty for a generated random 256-bit key, or provide a passphrase.
3. Click **Create key**. Copy the secret shown once into a password manager or another secure location independent of this WordPress database; confirm that you stored it before closing.
4. Edit the profile, enable **Encrypt backups**, select its **Key ID**, save and run a backup. Check its encrypted status and test restoring it with your external key copy.

Encryption uses libsodium streaming authenticated encryption for volumes, database parts and the file index. The manifest remains readable: encryption does not hide every piece of backup metadata. Checksums can be verified without the key, but browsing/decrypting and restoring encrypted content need the secret. There is no key recovery if you lose it.

For externally managed keys, `SWBACKUPIT_KEY_<ID>` can be supplied as a wp-config.php constant or environment variable; uppercase the ID and replace other characters with underscores. Use the secret shown at creation (`base64:…`) or the passphrase. Keep it out of version control and public configuration dumps. Detailed key handling: [security](https://github.com/sw-wordpress/sw-backupit/blob/main/docs/security.md) and [CLI reference](https://github.com/sw-wordpress/sw-backupit/blob/main/docs/wp-cli.md).

Deleting a key leaves old encrypted backups dependent on an external copy and prevents new runs of profiles that reference it. Keep old secrets for as long as their backups must remain recoverable.

## Find backups after catalog loss

On a configured target, open **Find backups**, select unknown entries and choose **Import**. **Download data into the local staging area** fetches data; without it the backup is only registered in the catalogue. Importing a catalog entry is not a successful restore test. For a standalone recovery from remote storage, use `fetch --with-chain` as described in the [CLI reference](https://github.com/sw-wordpress/sw-backupit/blob/main/docs/wp-cli.md).
