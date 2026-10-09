<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');
check_status(ACCESS_ADMINISTRATOR);

include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

global $template, $page, $conf;

// ---------------------------------------------------------------- actions

if (!empty($_POST['rf_action']))
{
  check_pwg_token();
  $flash = array('infos' => array(), 'errors' => array());

  switch ($_POST['rf_action'])
  {
    case 'recheck':
      rf_reconcile();
      $flash['infos'][] = 'Folders re-checked against albums.';
      break;

    case 'convert':
      $r = rf_convert_all(!empty($_POST['move_photos']));
      $flash['infos'][] = sprintf('%d album(s) now have folders, %d photo(s) moved out of upload/.', $r['albums'], $r['photos']);
      if ($r['failed'] > 0)
      {
        $flash['errors'][] = sprintf('%d item(s) could not be converted; see the log below.', $r['failed']);
      }
      break;

    case 'restore':
      $folder = isset($_POST['folder']) ? (string)$_POST['folder'] : '';
      $parent = isset($_POST['parent']) ? (string)$_POST['parent'] : 'original';
      if ('original' !== $parent)
      {
        $parent = intval($parent);
      }
      $name = isset($_POST['name']) ? trim(strip_tags((string)$_POST['name'])) : '';
      $r = rf_trash_restore($folder, $parent, '' === $name ? null : $name);
      if (is_int($r) or ctype_digit((string)$r))
      {
        $flash['infos'][] = 'Restored. <a href="'.get_root_url().'admin.php?page=album-'.$r.'">Open the album</a>';
      }
      else
      {
        $flash['errors'][] = 'Restore failed: '.htmlspecialchars((string)$r);
      }
      break;

    case 'delete':
      $folder = isset($_POST['folder']) ? (string)$_POST['folder'] : '';
      if (rf_trash_delete($folder))
      {
        $flash['infos'][] = 'Permanently deleted.';
      }
      else
      {
        $flash['errors'][] = 'Could not delete everything; see the log below.';
      }
      break;

    case 'empty':
      $n = 0;
      foreach (rf_trash_list() as $entry)
      {
        if (rf_trash_delete($entry['folder']))
        {
          $n++;
        }
      }
      $flash['infos'][] = sprintf('Trash emptied (%d item(s)).', $n);
      break;
  }

  $_SESSION['rf_flash'] = $flash;
  redirect(RF_ADMIN);
}

if (isset($_SESSION['rf_flash']))
{
  $page['infos'] = array_merge($page['infos'] ?? array(), $_SESSION['rf_flash']['infos']);
  $page['errors'] = array_merge($page['errors'] ?? array(), $_SESSION['rf_flash']['errors']);
  unset($_SESSION['rf_flash']);
}

// ---------------------------------------------------------------- data

function rf_human_size($bytes)
{
  $units = array('B', 'KB', 'MB', 'GB', 'TB');
  $i = 0;
  $bytes = (float)$bytes;
  while ($bytes >= 1024 and $i < count($units) - 1)
  {
    $bytes /= 1024;
    $i++;
  }
  return (0 == $i ? $bytes : number_format($bytes, 1)).' '.$units[$i];
}

// album list for the "restore to" picker: "Parent / Child / Grandchild"
$cat_rows = query2array('SELECT id, name, uppercats FROM '.CATEGORIES_TABLE.' ORDER BY global_rank;', 'id');
$album_options = array();
foreach ($cat_rows as $id => $row)
{
  $parts = array();
  foreach (explode(',', $row['uppercats']) as $uid)
  {
    if (isset($cat_rows[$uid]))
    {
      $parts[] = strip_tags(trigger_change('render_category_name', $cat_rows[$uid]['name'], 'realfolders'));
    }
  }
  $album_options[$id] = implode(' / ', $parts);
}

$trash = array();
foreach (rf_trash_list() as $e)
{
  $orig_parent = null;
  if (!empty($e['orig_parent_id']))
  {
    $orig_parent = isset($album_options[$e['orig_parent_id']]) ? $album_options[$e['orig_parent_id']] : false;
  }

  $trash[] = array(
    'FOLDER' => $e['folder'],
    'NAME' => strip_tags($e['album_name']),
    'ORIG_PATH' => $e['orig_path'],
    'ORIG_PARENT' => $orig_parent,       // null = was at root, false = parent gone
    'DELETED_ON' => format_date($e['deleted_on'], array('day', 'month', 'year', 'time')),
    'FILES' => $e['files'],
    'ALBUMS' => $e['albums'],
    'SIZE' => rf_human_size($e['bytes']),
    'EXISTS' => $e['exists'],
    'KNOWN' => !empty($e['id']),
    );
}

$log = query2array('SELECT * FROM '.RF_LOG_TABLE.' ORDER BY id DESC LIMIT 40;');
foreach ($log as &$l)
{
  $l['when'] = format_date($l['occured_on'], array('day', 'month', 'year', 'time'));
}
unset($l);

$status = rf_status();
$status['free_space_h'] = false === $status['free_space'] ? '?' : rf_human_size($status['free_space']);

$template->set_filename('plugin_admin_content', realpath(RF_PATH.'template/admin.tpl'));
$template->assign(array(
  'RF_STATUS' => $status,
  'RF_TRASH' => $trash,
  'RF_ALBUMS' => $album_options,
  'RF_LOG' => $log,
  'RF_ACTION' => RF_ADMIN,
  'RF_TOKEN' => get_pwg_token(),
  'RF_TRASH_DIR' => rf_galleries_url().RF_TRASH_DIR,
  ));
$template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
