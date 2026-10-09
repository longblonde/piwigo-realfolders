<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

class realfolders_maintain extends PluginMaintain
{
  private $albums;
  private $trash;
  private $log;

  function __construct($plugin_id)
  {
    parent::__construct($plugin_id);
    global $prefixeTable;
    $this->albums = $prefixeTable.'realfolders_albums';
    $this->trash = $prefixeTable.'realfolders_trash';
    $this->log = $prefixeTable.'realfolders_log';
  }

  function install($plugin_version, &$errors=array())
  {
    // last known on-disk location of every physical album, so we can tell
    // when Piwigo renamed or moved one and follow along on disk
    pwg_query('
CREATE TABLE IF NOT EXISTS `'.$this->albums.'` (
  `cat_id` int(11) unsigned NOT NULL,
  `parent_id` int(11) unsigned DEFAULT NULL,
  `path` text NOT NULL,
  `name` text NOT NULL,
  PRIMARY KEY (`cat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
;');

    pwg_query('
CREATE TABLE IF NOT EXISTS `'.$this->trash.'` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `folder` varchar(255) NOT NULL,
  `orig_path` text NOT NULL,
  `orig_parent_id` int(11) unsigned DEFAULT NULL,
  `album_name` text NOT NULL,
  `deleted_on` datetime NOT NULL,
  `manifest` mediumtext,
  PRIMARY KEY (`id`),
  UNIQUE KEY `folder` (`folder`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
;');

    pwg_query('
CREATE TABLE IF NOT EXISTS `'.$this->log.'` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `occured_on` datetime NOT NULL,
  `level` varchar(10) NOT NULL,
  `message` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
;');
  }

  function activate($plugin_version, &$errors=array())
  {
    $this->install($plugin_version, $errors);
  }

  function update($old_version, $new_version, &$errors=array())
  {
    $this->install($new_version, $errors);
  }

  function deactivate()
  {
  }

  function uninstall()
  {
    // Folders and the trash on disk are left exactly as they are; albums stay
    // physical, so Piwigo's own Synchronize keeps working with them.
    pwg_query('DROP TABLE IF EXISTS `'.$this->albums.'`;');
    pwg_query('DROP TABLE IF EXISTS `'.$this->trash.'`;');
    pwg_query('DROP TABLE IF EXISTS `'.$this->log.'`;');
  }
}
