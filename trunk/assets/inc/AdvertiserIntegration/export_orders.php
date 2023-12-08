<?php
/**
 * Order Data exporter for Katalys historical upload.
 *
 * Usage:
 * $ php export_orders.php | gzip > TMP_OrderDataForKatalys.json.gz
 *
 * Katalys needs to import historical order information to correctly attribute new orders
 * and remain compliant with your contract terms. The UI export feature will timeout with
 * large databases, so this script is a replacement that is more memory efficient.
 */
namespace revoffers\exporter;
declare(ticks=10);

// protect from web invocation
if (php_sapi_name() !== 'cli') die('Invalid invocation');
// run in closure for variable protection
run();

function run()
{
  if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, __NAMESPACE__ . "\\sighandle");
    pcntl_signal(SIGHUP, __NAMESPACE__ . "\\sighandle");
    pcntl_signal(SIGTERM, __NAMESPACE__ . "\\sighandle");
  }

  // require WP bootstrap
  require_once ABSPATH . 'wp-load.php';
  require_once __DIR__ . '/admin_func.php';

  ini_set('display_errors', 1);

  $tempFile = null;
  if (function_exists('posix_isatty') && posix_isatty(STDOUT)) {
    $tempFile = '/tmp/katalys-orders-export.json.gz';
    fwrite(STDERR, "Printing results to $tempFile\n");
    $fp = fopen("compress.zlib://$tempFile", 'w');
  } else {
    fwrite(STDERR, "Printing all results to STDOUT\n");
    $fp = STDOUT;
  }
  if (!$fp) throw new \RuntimeException("Failed to open file");

  $clearLine = `tput el`;
  $lines = 0;
  $startDate = '-1 year';
  $total = \revoffers\admin\countOrders($startDate);

  try {
    global $revoffers_break;
    $orderIterator = \revoffers\admin\iterateOrdersByDate($startDate, $revoffers_break);
    $iterator = \revoffers\admin\printOrdersToStream($orderIterator, $fp);
    foreach ($iterator as $i => $_) {
      $lines = $i + 1;// offset starts @ 0
      if ($i > 0 && $i % 5 === 0) {
        wp_cache_flush(); // clear memory
        fprintf(STDERR, "\r$clearLine %02.2f%%  %d / %d  (%2.1fM)", $i*100/$total, $i, $total, memory_get_usage()/1024/1024);
      }
    }
  } catch (\Exception $e) {
    fwrite(STDERR, "\nEXCEPTION: $e\n");
  }

  fclose($fp);
  fwrite(STDERR, "\nWrote $lines lines to " . ($tempFile ?: 'STDOUT') . "\n");
}

function sighandle()
{
  global $revoffers_break;
  $revoffers_break = true;
  fwrite(STDERR, " [EXITING...] ");
}
