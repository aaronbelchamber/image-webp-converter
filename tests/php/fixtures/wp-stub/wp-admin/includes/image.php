<?php
/**
 * Empty stand-in for WordPress's real wp-admin/includes/image.php, which
 * class-iwc-bulk-converter.php require_once's before calling
 * wp_generate_attachment_metadata(). That function itself is mocked via
 * Brain Monkey in tests (see WpdbTestCase), so this file only needs to
 * exist — it deliberately defines nothing.
 */
