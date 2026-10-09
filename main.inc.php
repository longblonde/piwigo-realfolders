<?php
/*
Plugin Name: Real Folders
Version: 1.0.0
Description: Albums are real folders on disk. Creating, renaming, moving and deleting albums creates, renames, moves and trashes folders under galleries/, and uploads land in their album's folder instead of upload/YYYY/MM/DD.
Plugin URI: auto
Author: Dan Wald
Has Settings: true
*/

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

if (basename(dirname(__FILE__)) != 'realfolders')
{
  add_event_handler('init', 'realfolders_bad_dirname');
  function realfolders_bad_dirname()
  {
    global $page;
    $page['errors'][] = 'Real Folders: the plugin folder must be named "realfolders".';
  }
  return;
}

global $prefixeTable;

define('RF_PATH', PHPWG_PLUGINS_PATH.'realfolders/');
define('RF_ADMIN', get_root_url().'admin.php?page=plugin-realfolders');
define('RF_ALBUMS_TABLE', $prefixeTable.'realfolders_albums');
define('RF_TRASH_TABLE', $prefixeTable.'realfolders_trash');
define('RF_LOG_TABLE', $prefixeTable.'realfolders_log');
define('RF_TRASH_DIR', '.rf-trash');
define('RF_LOCK_FILE', '.rf-lock');

include_once(RF_PATH.'include/functions.inc.php');

add_event_handler('init', 'rf_init');

// new album -> new folder
add_event_handler('create_virtual_category', 'rf_on_create_category');

// upload -> move the file from upload/Y/M/D into its album's folder
add_event_handler('loc_end_add_uploaded_file', 'rf_on_uploaded_file');

// album deleted -> its folder goes to the trash (fires after the DB rows are gone)
add_event_handler('delete_categories', 'rf_on_delete_categories');

// wrap the album web-service methods so renames/moves/deletes touch the disk
add_event_handler('ws_add_methods', 'rf_ws_add_methods', EVENT_HANDLER_PRIORITY_NEUTRAL + 10);

// safety net: re-check folders whenever the album admin pages load
add_event_handler('loc_begin_admin_page', 'rf_on_admin_page');

function rf_init()
{
  global $conf;

  // keep Piwigo's own Synchronize away from the trash folder
  if (!isset($conf['sync_exclude_folders']) or !is_array($conf['sync_exclude_folders']))
  {
    $conf['sync_exclude_folders'] = array();
  }
  $conf['sync_exclude_folders'][] = RF_TRASH_DIR;
}

function rf_on_admin_page()
{
  global $page;
  if (isset($page['page']) and in_array($page['page'], array('albums', 'album', 'cat_list')))
  {
    rf_reconcile();
  }
}
