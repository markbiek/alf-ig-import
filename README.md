# Antelope Instagram Import

This is a _very_ rough plugin which takes an Instagram export `.zip` and imports all of the image media items. The actual image files are added to the Media Library. A post (added to the selected category) is created for each media item and the image file is set as the Featured Image.

The import plugin includes and activates the [Action Scheduler](https://actionscheduler.org/) which can be accessed at `/wp-admin/tools.php?page=action-scheduler`. The import will be divided into chunks and each chunk will run as a separate scheduled action.

## How to use

**NOTE: Running this import will automatically create new posts for each imported item. Keep in mind that these new posts may show up in your RSS feed and send notifications or emails to all of your subscribers. If you have 1000 imported items, your subscribers will be notified/emailed 1000 times. You may want to take steps to disable sending emails during the import.**

You could temporarily disable email sending during import by creating a dummy plugin called `wp-content/plugins/disable-email.php` with the contents:

```
<?php
/**
 * Plugin Name: Disable Email Sending
 * Description: When active, WordPress will not send any emails.
 */

The plugin now sets `WP_IMPORTING` to `true` but I haven't tested if it works properly.

add_filter('wp_mail', '__return_false');
```

1. Install and activate this plugin.
2. Get an export of your [Instagram data](https://accountscenter.instagram.com/info_and_permissions/dyi/?entry_point=notification).
3. Upload the exported `.zip` file to the `uploads/` directory of your WordPress site.
4. Go to "Tools -> Instagram Import" in the `wp-admin` of your site.
5. You should see a pull down with your Instagram `.zip` file.
6. Select the category you want to have your photo posts added to.
7. Click "Import" and wait. You can refresh the admin page and it will show you where it is in the process.

## Troubleshooting

I highly recommend installing the https://wordpress.org/plugins/debug-log-manager/ plugin so you can see what the import is doing or if there are any errors.

You can cancel an in-progress import by clicking "Reset Import" on the admin page.

There is a cli command which will delete ALL POSTS AND MEDIA (not just those imported by this plugin) if you need to do a complete reset. Run `wp alf-ig-import` to see the name of the command. NOTE: The command is disabled by default. You'll need to remove a line of code to enable it. That's an exercise for you to figure out since that command is super dangerous.

In the event that something goes wrong and you go to the admin page and the "Import" button isn't visible, you'll need to delete the following WordPress options:

* `alf_process_instagram_import`
* `alf_complete_instagram_import`
* `alf_instagram_import_status`
* `alf_instagram_export_path`

You may also need to run `as_unschedule_all_actions( 'alf_process_instagram_import' );` to cancel any import scheduled actions that are stuck.
