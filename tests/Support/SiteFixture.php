<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Support;

use PDO;

/** Builds a small fake WordPress installation (files + database) for end-to-end tests. */
trait SiteFixture
{
    /**
     * @return array<string, string> relative path => content (for later comparison)
     */
    protected function buildSiteFiles(string $root): array
    {
        $files = [
            'wp-config.php' => "<?php\ndefine( 'DB_NAME', 'old_db' );\ndefine('DB_USER', 'old_user');\ndefine( 'DB_PASSWORD', 'old\\'pass' );\ndefine( 'DB_HOST', 'localhost' );\n\$table_prefix = 'wp_';\n",
            'index.php' => "<?php\n// front controller\n",
            'wp-content/themes/t/style.css' => str_repeat("body{color:#123}\n", 500),
            'wp-content/plugins/p/p.php' => "<?php\n/* plugin */\n",
            'wp-content/uploads/2026/10/ünï cödé 1.txt' => 'unicode name',
            'wp-content/uploads/2026/10/with space & "quote".txt' => 'special chars',
            'wp-content/uploads/binary.bin' => random_bytes(70000),
            'wp-content/uploads/empty.txt' => '',
        ];
        for ($i = 0; $i < 60; $i++) {
            $files["wp-content/uploads/many/f{$i}.txt"] = str_repeat("line $i\n", 20 + $i * 7);
        }
        // Larger than the (small) test volume size -> must be chunked across volumes.
        $files['wp-content/uploads/big.dat'] = random_bytes(450000) . str_repeat('A', 300000);
        foreach ($files as $path => $content) {
            $full = $root . '/' . $path;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0775, true);
            }
            file_put_contents($full, $content);
        }
        mkdir($root . '/wp-content/empty-dir', 0775, true);

        return $files;
    }

    protected function seedSiteDatabase(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE wp_options (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NOT NULL UNIQUE, option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL DEFAULT \'yes\')');
        $pdo->exec('CREATE TABLE wp_posts (ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, post_title TEXT NOT NULL, post_content LONGTEXT NOT NULL, post_date DATETIME NOT NULL DEFAULT \'0000-00-00 00:00:00\')');
        $options = $pdo->prepare('INSERT INTO wp_options (option_name, option_value) VALUES (?, ?)');
        $options->execute(['siteurl', 'https://old.example.com']);
        $options->execute(['serialized', serialize(['url' => 'https://old.example.com', 'n' => 3])]);
        $posts = $pdo->prepare('INSERT INTO wp_posts (post_title, post_content) VALUES (?, ?)');
        for ($i = 0; $i < 120; $i++) {
            $posts->execute(["Post $i 😀", "<p>It's \"content\" \\ with\nnewlines $i</p>"]);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function snapshotTree(string $root): array
    {
        $result = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($root) + 1);
            $result[$relative] = $item->isDir() ? 'DIR' : (string) hash_file('sha256', $item->getPathname());
        }
        ksort($result);

        return $result;
    }
}
