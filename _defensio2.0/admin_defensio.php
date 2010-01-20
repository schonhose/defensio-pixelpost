<?php
/*
Requires Pixelpost version 1.7 or newer
Defensio ADMIN-side ADDON-Version 2.0.0

Written by: Schonhose
@:			schonhose@pixelpost.org
WWW:		http://foto.schonhose.nl/

Pixelpost www: http://www.pixelpost.org/

License: http://www.gnu.org/copyleft/gpl.html

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
*/

//*******************************************************************************************************************
//   GENERAL INFORMATION DISPLAYED IN ADDON LIST
//*******************************************************************************************************************
require_once ('lib/pixelpost/defensio_pixelpost.php');

// make sure we disable the old Defensio addon
defensio_disable_old_defensio();

$addon_name = "Pixelpost Defensio comment filter (Admin Side)";
$addon_version = '2.0.0';
$akismet_warning = defensio_check_status_askimet();
if (version_compare(PHP_VERSION, '5.0.0', '<')) 
{
    $php_version_message = "<br /><br /><font color=\"red\"><strong>This addon requires PHP version 5.0.0 and it seems you are on PHP version " . PHP_VERSION .". This version of the Defensio addon will not work on your setup.</strong></font>";
} else {
    $php_version_message = "<br /><br />Running on PHP version " . PHP_VERSION .": <font color=\"green\"><strong>OK</strong></font>.";
}
$addon_description =
    "<a name='defensio'></a>Pixelpost Add-On to filter spam using Defensio (<a href='http://www.defensio.com' target='_blank'>Info</a>). This is the admin side of the addon, for displaying comments and changing options." .
    $php_version_message . $akismet_warning . "<br />Please read the <a href='../addons/_defensio2.0/manual/Defensio_v2.0_manual.html'>manual</a> for more information.<br /><br /><img src=\"../addons/_defensio2.0/images/poweredbyd.png\">";


//*******************************************************************************************************************
//   WORKSPACE STUFF
//*******************************************************************************************************************

add_admin_functions("defensio_settings", "additional_spam_measures", "", "");


// only perform this when the PHP version >= 5.0.0
if (version_compare(PHP_VERSION, '5.0.0', '>=')) 
{
    require_once ('lib/defensio-php/Defensio.php');

    add_admin_functions("get_defensio_pages", "comments", "comments", "Defensio");
    add_admin_functions('get_defensio_links', 'show_commentbuttons_top');
    add_admin_functions('get_defensio_links2', 'show_commentbuttons_bottom');
    add_admin_functions('get_defensio_pages', 'pages_commentbuttons');
    add_admin_functions('defensio_commentlist', 'single_comment_list');
    add_admin_functions('get_defensio_style', 'admin_html_head');

    //*******************************************************************************************************************
    //   CHECK DATABASE AND CREATE NECESSARY TABLES/FIELDS
    //*******************************************************************************************************************
    // Update comment table
    if (!is_field_exists('spaminess', 'comments')) {
        // create field
        update_comments_table_for_defensio();
    }
    // Create options table
    if (!is_field_exists('key', 'defensio')) {
        create_options_table_for_defensio();
    }
    // Update options table
    if (!is_field_exists('defensio_stats', 'defensio')) {
        update_options_table_for_defensio1_1();
    }
    // Update database for Defensio 2.0
    if (!is_field_exists('status', 'comments')) {
        update_database_for_defensio2_0();
    }

    $defensio_result = mysql_query("SELECT * FROM `{$pixelpost_db_prefix}defensio` LIMIT 1") or
        die(mysql_error());
    $defensio_conf = mysql_fetch_array($defensio_result);
    $timelimit = 60 * 5;
    if (($defensio_conf['defensio_comments_processed_at'] == null) or (mktime() - $defensio_conf['defensio_comments_processed_at'] > $timelimit)) {
        defensio_process_unprocessed($defensio_conf);
    }


    //*******************************************************************************************************************
    //   GLOBAL DECLARATION
    //*******************************************************************************************************************
    $GLOBALS['defensio_conf'] = $defensio_conf;
    global $tpl;
    $_SESSION['divide_somewhat'] = "<h2>Somewhat Spammy</h2>";
    $_SESSION['divide_moderately'] = "<h2>Moderately Spammy</h2>";
    $_SESSION['divide_quite'] = "<h2>Quite Spammy</h2>";
    $_SESSION['divide_extreme'] = "<h2>Very Spammy</h2>";


    // widget support
    $defensio_widget = defensio_counter($defensio_conf);
    $tpl = ereg_replace("<DEFENSIO_WIDGET>", $defensio_widget, $tpl);

    //*******************************************************************************************************************
    //   MARK COMMENTS AS SPAM OR HAM (SELECTOR BASED ON FORM SUBMIT)
    //*******************************************************************************************************************
    //Check whether ADMIN has submitted comment to mark as spam for Defensio
    if (isset($_GET['view']) && $_GET['view'] == 'comments' && isset($_GET['action']) and
        $_GET['action'] == 'defensiospam') {
        defensio_submit_spam_comment($defensio_conf);
    }

    //Check whether ADMIN has submitted comment to mark as ham for Defensio
    if (isset($_GET['view']) && $_GET['view'] == 'comments' && isset($_GET['action']) and
        $_GET['action'] == 'defensionotspam') {
        defensio_submit_nonspam_comment($defensio_conf);
    }

    if (isset($_GET['view']) && $_GET['view'] == 'comments' && isset($_GET['action']) and
        $_GET['action'] == 'defensioprocessall') {
        defensio_process_unprocessed($defensio_conf);
    }


    if (isset($_GET['view']) && $_GET['view'] == 'comments' && isset($_GET['action']) and
        $_GET['action'] == 'defensiogetresults') {
        $defensio = new Defensio($defensio_conf['key']);
        if (array_shift($defensio->getUser()) == 200) {
            $comment_id = (int)$_GET['cid'];
            // get the comment info in question
            $query = "SELECT `signature` FROM `{$pixelpost_db_prefix}comments` WHERE `id` = '" .
                $comment_id . "'";
            $defensio_result = mysql_query($query) or die(mysql_error());
            $row = mysql_fetch_array($defensio_result);
            $get_result = $defensio->getDocument($row[0]);
            // we always try to get the results here.
            defensio_process_comment_pixelpost($get_result, false);
        } else {
            die("The API key is invalid!!! Bye Bye.");
        }
    }

    //Check whether ADMIN has submitted a comment to resend to Defensio
    if (isset($_GET['view']) && $_GET['view'] == 'comments' && isset($_GET['action']) and
        $_GET['action'] == 'defensiorecheck') {
        // build $comment array used for testing.
        $comment_id = (int)$_GET['cid'];
        // get the comment info in question
        $query = "SELECT * FROM `{$pixelpost_db_prefix}comments` WHERE `id` = '" . $comment_id .
            "'";
        $defensio_result = mysql_query($query) or die(mysql_error());
        $row = mysql_fetch_array($defensio_result);
        $document = array('client' => 'Pixelpost Defensio Addon | ' . $addon_version .
            ' | Schonhose | schonhose@pixelpost.org', 'content' => $row['message'],
            'platform' => 'pixelpost', 'type' => 'comment', 'async' => 'true',
            'async-callback' => $defensio_conf['blog'] .
            'addons/_defensio2.0/lib/callback.php?id=' . md5($defensio_conf['key']),
            'author-email' => $row['email'], 'author-ip' => $row['ip'], 'author-logged-in' =>
            'false', 'author-name' => $row['name'], 'parent-document-date' =>
            defensio_get_datetime_post($row['parent_id']), 'parent-document-permalink' => $defensio_conf['blog'] .
            "index.php?showimage=" . $row['parent_id'], 'referrer' => $_SERVER['HTTP_REFERER']);
        $defensio = new Defensio($defensio_conf['key']);
        /**
         * Only continue with Defensio if the API key is valid
         */
        if (array_shift($defensio->getUser()) == 200) {
            $post_result = $defensio->postDocument($document);
            // we always do a NEW request here.
            defensio_process_comment_pixelpost($post_result, true, $comment_id);
        } else {
            die("The API key is invalid!!! Bye Bye.");
        }
    }

    //Check whether ADMIN has submitted an empty quarantine request
    if (isset($_GET['view']) && $_GET['view'] == 'comments' && isset($_GET['action']) and
        $_GET['action'] == 'emptyquarantine') {
        $query = "DELETE FROM {$pixelpost_db_prefix}comments WHERE publish='dfn'";
        $defensio_result = mysql_query($query);
        $GLOBALS['defensio_result_message'] =
            '<div class="jcaption confirm">The Defensio Quarantine has been emptied.</div>';
    }

    /**
     * create_options_table_for_defensio()
     * 
     * @return
     */
    function create_options_table_for_defensio()
    {
        // create the options table for defensio
        global $cfgrow, $pixelpost_db_prefix;
        $query = "CREATE TABLE `{$pixelpost_db_prefix}defensio` (
			`server` VARCHAR( 100 ) NULL DEFAULT 'api.defensio.com',
			`path` VARCHAR( 100 ) NULL DEFAULT 'blog',
			`api-version` VARCHAR( 100 ) NULL DEFAULT '1.1',
			`format` VARCHAR( 100 ) NULL DEFAULT 'yaml',
			`blog` VARCHAR( 100 ) NULL DEFAULT '" . $cfgrow['siteurl'] . "',
			`post-timeout` INTEGER NULL DEFAULT 10,
			`threshold` FLOAT NULL DEFAULT 0.8,
			`remove_older_than` INTEGER NULL DEFAULT 0,
			`remove_older_than_days` INTEGER NULL DEFAULT 15,
			`key` VARCHAR( 100 ) NULL
   )";
        $defensio_result = mysql_query($query) or die(mysql_error());
        $query = "INSERT INTO `{$pixelpost_db_prefix}defensio` (
						`server` ,`path` ,`api-version` ,`format` ,`blog` ,`post-timeout` ,`threshold` ,`remove_older_than` ,
						`remove_older_than_days` ,`key`) VALUES (
						'api.defensio.com', 'blog', '1.1', 'yaml', '" . $cfgrow['siteurl'] .
            "', '10', '0.8', '0', '15', NULL);";
        $defensio_result = mysql_query($query) or die(mysql_error());
    }

    /**
     * update_options_table_for_defensio1_1()
     * 
     * @return
     */
    function update_options_table_for_defensio1_1()
    {
        // update options table for defensio 1.1
        global $cfgrow, $pixelpost_db_prefix;
        $query = "ALTER TABLE `{$pixelpost_db_prefix}defensio` 
			ADD `defensio_stats` TEXT,
			ADD `defensio_stats_updated_at` VARCHAR( 20 ) NULL DEFAULT '1195732629',
			ADD `defensio_widget_image` VARCHAR( 20 ) NULL DEFAULT 'dark',
			ADD `defensio_widget_align` VARCHAR( 20 ) NULL DEFAULT 'left'";
        $defensio_result = mysql_query($query) or die(mysql_error());
    }

    /**
     * update_comments_table_for_defensio()
     * 
     * @return
     */
    function update_comments_table_for_defensio()
    {
        // add two fields to the comment table
        global $cfgrow, $pixelpost_db_prefix;
        $query = "ALTER TABLE `{$pixelpost_db_prefix}comments` ADD `spaminess` FLOAT NULL ,ADD `signature` VARCHAR( 55 ) NULL";
        $defensio_result = mysql_query($query) or die(mysql_error());
    }

    /**
     * update_database_for_defensio2_0()
     * 
     * @return
     */
    function update_database_for_defensio2_0()
    {
        // add two fields to the comment table
        global $cfgrow, $pixelpost_db_prefix;

        $query = "ALTER TABLE `{$pixelpost_db_prefix}comments` ADD `status` VARCHAR( 10 ) NULL, ADD `allow` BOOL NULL ,ADD `classification` VARCHAR( 55 ) NULL";
        $defensio_result = mysql_query($query) or die(mysql_error());
        $query = "ALTER TABLE `{$pixelpost_db_prefix}defensio` DROP `server`, DROP `path`, DROP `api-version`, DROP `format`";
        $defensio_result = mysql_query($query) or die(mysql_error());
        $query = "ALTER TABLE `{$pixelpost_db_prefix}defensio` ADD `defensio_comments_processed_at` VARCHAR( 20 ) NULL DEFAULT '1195732629'";
        $defensio_result = mysql_query($query) or die(mysql_error());
    }
}
?>