# Manual update mechanism

If you need to use your own update mechanism instead of the one provided by WordPress through the wordpress.org distribution, then copy the below code into your plugin file, and move this folder to be at `{plugin}/updates`.

```
/**
 * Custom Update Mechanism
 *
 * Only use this if plugin is being managed manually (outside of WordPress Repository).
 */
include __DIR__ . '/updates/register.php';
```