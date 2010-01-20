<?PHP
/*
Requires Pixelpost version 1.7 or newer
Defensio FRONT-side ADDON-Version 2.0.0

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

$addon_name = "Pixelpost Defensio comment filter (Front Side)";
$addon_version = '2.0.0';
$akismet_warning = defensio_check_status_askimet();
if (version_compare(PHP_VERSION, '5.0.0', '<')) {
    $php_version_message = "<br /><br /><font color=\"red\"><strong>This addon requires PHP version 5.0.0 and it seems you are on PHP version " .
        PHP_VERSION . ". This version of the Defensio addon will not work on your setup.</strong></font>";
} else {
    $php_version_message = "<br /><br />Running on PHP version " . PHP_VERSION .
        ": <font color=\"green\"><strong>OK</strong></font>.";
}
$addon_description =
    "<a name='defensio'></a>Pixelpost Add-On to filter spam using Defensio (<a href='http://www.defensio.com' target='_blank'>Info</a>). This is the front side of the addon, it checks the comments and marks them in the database." .
    $php_version_message . $akismet_warning . "<br />Please read the <a href='../addons/_defensio2.0/manual/Defensio_v2.0_manual.html'>manual</a> for more information.<br /><br /><img src=\"../addons/_defensio2.0/images/poweredbyd.png\">";


if (version_compare(PHP_VERSION, '5.0.0', '>=')) {

    //*******************************************************************************************************************
    //   WORKSPACE STUFF
    //*******************************************************************************************************************
    add_front_functions('check_comment_with_defensio', 'comment_accepted');
    require_once ('lib/defensio-php/Defensio.php');
    $defensio_result = mysql_query("SELECT * FROM `{$pixelpost_db_prefix}defensio` LIMIT 1") or
        die(mysql_error());
    $defensio_conf = mysql_fetch_array($defensio_result);
    $timelimit = 60 * 15; //number of seconds.
    if (($defensio_conf['defensio_comments_processed_at'] == null) or (mktime() - $defensio_conf['defensio_comments_processed_at'] > $timelimit)) {
        defensio_process_unprocessed($defensio_conf);
    }


    //*******************************************************************************************************************
    //   MAIN FUNCTION CALL
    //*******************************************************************************************************************
    /**
     * check_comment_with_defensio()
     * 
     * @return
     */
    function check_comment_with_defensio()
    {
        global $pixelpost_db_prefix, $cfgrow, $parent_id, $message, $ip, $name, $url;
        require_once ('addons/_defensio2.0/lib/defensio-php/Defensio.php');
        require_once ('addons/_defensio2.0/lib/pixelpost/defensio_pixelpost.php');

        $defensio_result = mysql_query("SELECT * FROM `{$pixelpost_db_prefix}defensio` LIMIT 1") or
            die(mysql_error());
        $defensio_conf = mysql_fetch_array($defensio_result);
        $defensio = new Defensio($defensio_conf['key']);
        $document = array();
        // store the $cfgrow['commentemail'] in a seperate temp variable and set it to no
        $tmp_commentmail = $cfgrow['commentemail'];
        $cfgrow['commentemail'] = 'no';
        
        // first update the comment in the database, assume it has failed.
        // sometimes the callback isn't issued properly.
        $query = "UPDATE {$pixelpost_db_prefix}comments 
            SET publish = 'dfn',
            `spaminess` = '-1',
            `status` = 'fail'  
             WHERE id = last_insert_id()";
        mysql_query($query);
        /**
         * Only continue with Defensio if the API key is valid
         */
        if (array_shift($defensio->getUser()) == 200) {
            $document = array('client' => 'Pixelpost Defensio Addon | ' . $addon_version .
                ' | Schonhose | schonhose@pixelpost.org', 'content' => $message, 'platform' =>
                'pixelpost', 'type' => 'comment', 'async' => 'true', 'async-callback' => $defensio_conf['blog'] .
                'addons/_defensio2.0/lib/callback.php?id=' . md5($defensio_conf['key']),
                'author-email' => $email, 'author-ip' => $ip, 'author-logged-in' => 'false',
                'author-name' => $name, 'parent-document-date' => defensio_get_datetime_post($parent_id),
                'parent-document-permalink' => $defensio_conf['blog'] . "index.php?showimage=" .
                $parent_id, 'referrer' => $_SERVER['HTTP_REFERER']);
            $post_result = $defensio->postDocument($document);
            $cfgrow['commentemail']=$tmp_commentmail;
            defensio_process_comment_pixelpost($post_result, true);
        }
    }
}
?>