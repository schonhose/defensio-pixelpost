<?php
/*
Requires Pixelpost version 1.7 or newer
Defensio Supporting functions

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

/**
 * defensio_process_comment_pixelpost()
 * 
 * @param mixed $defensioXML_result
 * @param mixed $firstcall
 * @param mixed $comment_id
 * @return
 */
function defensio_process_comment_pixelpost($defensioXML_result, $firstcall, $comment_id = null)
{
    global $pixelpost_db_prefix, $cfgrow;
    // Depending on the fact this is the first call to the database we either have
    // to update based upon last_insert_id, signature or comment id (if failed previously).
    if ($firstcall) {
        if ($comment_id == null) {
            $where_clause = 'WHERE id = last_insert_id()';
        } else {
            $where_clause = 'WHERE id = ' . $comment_id;
        }
    } else {
        $where_clause = "WHERE `signature` = '" . $defensioXML_result[1]->signature .
            "'";
    }
    if ($defensioXML_result[0] == 200) {
        // succesful query to Defensio
        switch ($defensioXML_result[1]->status) {
            case 'success':
                // we have to see if the comment was classified as SPAM or not
                if (($defensioXML_result[1]->allow == 'true') && ($defensioXML_result[1]->
                    classification == 'legitimate')) {
                    // The comment has been classified als good by Defensio, so we can publish it
                    $query = "UPDATE {$pixelpost_db_prefix}comments 
                        SET publish = 'yes', 
                        `spaminess` = '" . $defensioXML_result[1]->spaminess .
                        "',
                        `status` = '" . $defensioXML_result[1]->status . "',
                        `allow` = '" . $defensioXML_result[1]->allow . "',
                        `classification` = '" . $defensioXML_result[1]->
                        classification . "' " . $where_clause;
                    $result = mysql_query($query);
                    if ($cfgrow['commentemail'] == 'yes') {
                        // we need to send an email to the user
                        $query = "SELECT `{$pixelpost_db_prefix}comments`.`parent_id`, `{$pixelpost_db_prefix}comments`.`url`, 
 												`{$pixelpost_db_prefix}comments`.`name`, `{$pixelpost_db_prefix}comments`.`email`, 
 												`{$pixelpost_db_prefix}comments`.`message`, `{$pixelpost_db_prefix}pixelpost`.`image` 
 												FROM `{$pixelpost_db_prefix}comments`, `{$pixelpost_db_prefix}pixelpost` " . $where_clause . " AND `{$pixelpost_db_prefix}comments`.`parent_id` = `{$pixelpost_db_prefix}pixelpost`.`id`";
                        $comment_info = mysql_query($query) or die(mysql_error());
                        $comment = mysql_fetch_array($comment_info, MYSQL_ASSOC);
                        sendout_email($comment, $cfgrow);
                    }
                    eval_addon_front_workspace('comment_passed_askimet');
                } else {
                    $cfgrow['commentemail'] = 'no';
                    // Defensio thinks it is SPAM so we keep it in our quarantine
                    // We do update the values for spaminess and status
                    $query = "UPDATE {$pixelpost_db_prefix}comments 
                        SET publish = 'dfn', 
                        `spaminess` = '" . $defensioXML_result[1]->spaminess .
                        "',
                        `status` = '" . $defensioXML_result[1]->status . "',
                        `allow` = '" . $defensioXML_result[1]->allow . "',
                        `classification` = '" . $defensioXML_result[1]->
                        classification . "'" . $where_clause;
                    $result = mysql_query($query) or die(mysql_error());
                    eval_addon_front_workspace('comment_blocked_askimet');
                }
                break;
            case 'pending':
                $cfgrow['commentemail'] = 'no';
                // we update the table
                $query = "UPDATE {$pixelpost_db_prefix}comments 
                    SET `publish` = 'dfn',
                    `spaminess` = '-1', 
                    `signature` = '" . $defensioXML_result[1]->signature . "', 
                    `status` = '" . $defensioXML_result[1]->status . "' " . $where_clause;
                $result = mysql_query($query) or die(mysql_error());
                // and leave the rest to the callback function.
                break;
            case 'fail':
                // we update the table
                $cfgrow['commentemail'] = 'no';
                $query = "UPDATE {$pixelpost_db_prefix}comments 
                    SET `publish` = 'dfn',
                    `spaminess` = '-1', 
                    `status` = 'fail' " . $where_clause;
                $result = mysql_query($query) or die(mysql_error());
                eval_addon_front_workspace('comment_blocked_askimet');
                break;
            default:
                // we update the table
                $cfgrow['commentemail'] = 'no';
                $query = "UPDATE {$pixelpost_db_prefix}comments 
                    SET `publish` = 'dfn',
                    `spaminess` = '-1', 
                    `status` = 'fail' " . $where_clause;
                $result = mysql_query($query) or die(mysql_error());
                eval_addon_front_workspace('comment_blocked_askimet');
                break;
        }
    } else {
        // the query to Defensio failed for some reason
        // Assume it is a SPAM comment
        $cfgrow['commentemail'] = 'no';
        $query = "UPDATE {$pixelpost_db_prefix}comments 
            SET `publish` = 'dfn',
            `spaminess` = '-1',
            `status` = '" . $defensioXML_result[1]->status . "' " . $where_clause;
        $result = mysql_query($query) or die(mysql_error());
        eval_addon_front_workspace('comment_blocked_askimet');
    }
    return $return;
}

/**
 * get_defensio_links()
 * 
 * @return
 */
function get_defensio_links()
{
    global $pixelpost_db_prefix, $moderate_where, $cfgrow;
    $defensio_conf = $GLOBALS['defensio_conf'];
    if (isset($_GET['commentsview']) && $_GET['commentsview'] == 'defensio') {
        echo "<a href=\"http://defensio.com\"><img style=\"width: 160px; height: 25px;float:right;margin-top:-35px;border:0px;\" src=\"../addons/_defensio2.0/images/poweredbyd.png\"></a>";
        echo "<span style=\"clear:both;\" />&nbsp;<span />";
        echo "<input class='cmnt-buttons' type='submit' name='defensionotspam' value='Report as NOT Spam To Defensio' onclick=\"
      			document.getElementById('managecomments').action='" . $_SERVER['PHP_SELF'] .
            "?" . $_SERVER['QUERY_STRING'] . "&amp;action=defensionotspam'
    			return confirm('Report all selected comments as Not Spam to Defensio?');\" />";
        echo "&nbsp;";
    }
    if (isset($_GET['show']) || !isset($_GET['commentsview'])) {
        echo "  <input class='cmnt-buttons' type='submit' name='defensiospam' value='Report as Spam To Defensio' onclick=\"
      			document.getElementById('managecomments').action='" . $_SERVER['PHP_SELF'] .
            "?" . $_SERVER['QUERY_STRING'] . "&amp;action=defensiospam'
    			return confirm('Report all selected comments as Spam to Defensio?');\" />";
    }
    if (isset($_GET['commentsview']) && $_GET['commentsview'] == 'defensio') {
        // get obvious spam
        $defensio_result = mysql_query("select count(*) as count from `" . $pixelpost_db_prefix .
            "comments` WHERE `publish`='dfn' AND `spaminess` < '" . $defensio_conf['threshold'] .
            "'") or die(mysql_error());
        $defensio_count_smaller_threshold = mysql_fetch_array($defensio_result);
        $defensio_result = mysql_query("select count(*) as count from `" . $pixelpost_db_prefix .
            "comments` WHERE `publish`='dfn' AND `spaminess` >= '" . $defensio_conf['threshold'] .
            "'") or die(mysql_error());
        $defensio_count_larger_threshold = mysql_fetch_array($defensio_result);
        echo "<br /><br />";
        echo "<input id=\"defensio_hide_very_spam\" name=\"defensio_hide_very_spam\" value=\"1\"" .
            $_SESSION['defensio_hide_very_spam'] . " onclick=\"javascript:this.form.submit();\" type=\"checkbox\"> Hide obvious spam (" .
            $defensio_count_larger_threshold['count'] . ")";
        echo "&nbsp;&nbsp;<input class='cmnt-buttons' type='submit' name='emptyquarantine' value='Empty Quarantine' onclick=\"
		    document.getElementById('managecomments').action='" . $_SERVER['PHP_SELF'] .
            "?" . $_SERVER['QUERY_STRING'] . "&amp;action=emptyquarantine'
		    return confirm('Are you sure you want to empty the Defensio Quarantine?');\" />";
        echo "&nbsp;<input class='cmnt-buttons' type='submit' name='defensioprocessall]' value='Process every failed and pending comments' onclick=\"
      			document.getElementById('managecomments').action='" . $_SERVER['PHP_SELF'] .
            "?" . $_SERVER['QUERY_STRING'] . "&amp;action=defensioprocessall'
    			return confirm('Do you want to retry all failed and pending comments?');\" />";

        echo "<input type=\"hidden\" value=\"dummy\" name=\"dummy\">";
        if ((isset($GLOBALS['defensio_result_message'])) && ($GLOBALS['defensio_result_message'] !=
            "")) {
            echo "<br /><br />" . $GLOBALS['defensio_result_message'];
        }
        if (($defensio_count_smaller_threshold['count'] < 1) && ($_SESSION['defensio_hide_very_spam'] !=
            "")) {
            echo "<div class=\"content\">Your quarantine is empty. ";
            if ($defensio_count_larger_threshold['count'] > 0) {
                echo "However, you are hiding " . $defensio_count_larger_threshold['count'] .
                    " obvious spam messages.";
            }
            echo "</div>";
        }
    }
}

/**
 * get_defensio_links2()
 * 
 * @return
 */
function get_defensio_links2()
{
    global $pixelpost_db_prefix, $moderate_where, $cfgrow, $defensio;
    $defensio_conf = $GLOBALS['defensio_conf'];
    // Echo the links
    if (isset($_GET['commentsview']) && $_GET['commentsview'] == 'defensio') {
        echo defensio_show_stats($defensio_conf) . "<br />";
        echo "	<input class='cmnt-buttons' type='submit' name='defensionotspam' value='Report as NOT Spam To Defensio' onclick=\"
      		document.getElementById('managecomments').action='" . $_SERVER['PHP_SELF'] .
            "?" . $_SERVER['QUERY_STRING'] . "&amp;action=defensionotspam'
      		return confirm('Report all selected comments as Not Spam to Defensio?');\" />";
        echo "&nbsp;";
    }
    if (isset($_GET['show']) || !isset($_GET['commentsview'])) {
        echo " <input class='cmnt-buttons' type='submit' name='defensiospam' value='Report as Spam To Defensio' onclick=\"
      		document.getElementById('managecomments').action='" . $_SERVER['PHP_SELF'] .
            "?" . $_SERVER['QUERY_STRING'] . "&amp;action=defensiospam'
      		return confirm('Report all selected comments as Spam to Defensio?');\" />";
    }
    // Delete comments older than X days and marked as SPAM by Defensio
    if ($defensio_conf['remove_older_than'] == 1) {
        $query = "DELETE FROM {$pixelpost_db_prefix}comments WHERE (TO_DAYS(CURDATE()) - TO_DAYS(`datetime`)) > " .
            $defensio_conf['remove_older_than_days'] . " AND publish='dfn'";
        $defensio_result = mysql_query($query);
    }
}

/**
 * defensio_commentlist()
 * 
 * @return
 */
function defensio_commentlist()
{
    global $comment_row_class, $id, $pixelpost_db_prefix, $comment_meta_information,
        $comment_divider_header, $admin_lang_cmnt_commenter, $datetime, $admin_lang_cmnt_ip,
        $ip, $divide_somewhat, $divide_moderately, $divide_quite, $divide_extreme;
    // the headers have not been set
    if (isset($_GET['commentsview']) && $_GET['commentsview'] == 'defensio') {
        $query = "SELECT `spaminess`, `status` FROM {$pixelpost_db_prefix}comments WHERE `id`='" .
            $id . "'";
        $defensio_result = mysql_query($query) or die(mysql_error());
        while ($row = mysql_fetch_array($defensio_result)) {
            $spaminess = $row[0];
            $status = $row[1];
        }
        $comment_row_class = "defensio_" . defensio_class_for_spaminess($spaminess);
        if ($spaminess > 0) {
            $spaminess_show = $spaminess * 100 . "%";
            $menu_items = null;
        } else {
            // always clean the url
            if ($status == 'pending') {
                $url_param = explode('&action=defensiorecheck', $_SERVER['QUERY_STRING']);
                $spaminess_show = "<span style='color:blue;font-weight:bold;'>PENDING (results are not in)</span>";
                $menu_items = "<a href='" . $_SERVER['PHP_SELF'] . "?" . $url_param[0] .
                    "&amp;action=defensiogetresults&amp;cid=" . $id .
                    "'><img style='border:none;height:32px;' src='../addons/_defensio2.0/images/get.png' alt='Click here to get the results of this comment.' /> </a>";
            } elseif ($status == 'fail') {
                $url_param = explode('&action=defensiorecheck', $_SERVER['QUERY_STRING']);
                $spaminess_show = "<span style='color:red;font-weight:bold;'>UNKNOWN (request failed)</span>";
                $menu_items = "<a href='" . $_SERVER['PHP_SELF'] . "?" . $url_param[0] .
                    "&amp;action=defensiorecheck&amp;cid=" . $id .
                    "'><img style='border:none;height:32px;' src='../addons/_defensio2.0/images/reload.png' alt='Click here to re-evaluate this comment.' /> </a>";
            }
        }
        // overide the comment meta information
        $comment_meta_information = "$menu_items <i>$admin_lang_cmnt_commenter $datetime. $admin_lang_cmnt_ip  $ip. Spaminess: $spaminess_show.<br /></i>";

        if ($spaminess <= 0.55) {
            $comment_divider_header = $_SESSION['divide_somewhat'];
            $_SESSION['divide_somewhat'] = null;
        }
        if (($spaminess > 0.55) && ($spaminess <= 0.77)) {
            $comment_divider_header = $_SESSION['divide_moderately'];
            $_SESSION['divide_moderately'] = null;
        }
        if (($spaminess > 0.77) && ($spaminess <= 0.9)) {
            $comment_divider_header = $_SESSION['divide_quite'];
            $_SESSION['divide_quite'] = null;
        }
        if ($spaminess > 0.9) {
            $comment_divider_header = $_SESSION['divide_extreme'];
            $_SESSION['divide_extreme'] = null;
        }
    }
}

/**
 * defensio_check_status_askimet()
 * 
 * @return
 */
function defensio_check_status_askimet()
{
    global $pixelpost_db_prefix;
    $defensio_result = mysql_query("SELECT `status` FROM `{$pixelpost_db_prefix}addons` WHERE `addon_name` LIKE '%akismet%' and `type`='front'") or
        die(mysql_error());
    $akismet = mysql_fetch_array($defensio_result);
    if ($akismet['status'] == 'on') {
        $akismet_warning = "<br /><br /><font color=\"red\"><strong>Defensio is not designed to work with Akismet. It is suggested to disable Akismet if you want to use Defensio.</strong></font>";
    } else {
        $akismet_warning = null;
    }
    return $akismet_warning;
}

/**
 * defensio_disable_old_defensio()
 * 
 * @return
 */
function defensio_disable_old_defensio()
{
    global $pixelpost_db_prefix;
    $defensio_result = mysql_query("SELECT `status` FROM `{$pixelpost_db_prefix}addons` WHERE `addon_name` LIKE '%_defensio/%' and `type`='front'") or
        die(mysql_error());
    $defensio_front = mysql_fetch_array($defensio_result);
    if ($defensio_front['status'] == 'on') {
        $defensio_result = mysql_query("UPDATE {$pixelpost_db_prefix}addons SET status = 'off'WHERE `addon_name` LIKE '%_defensio/%' and `type`='front'") or
            die(mysql_error());
    }
    $defensio_result = mysql_query("SELECT `status` FROM `{$pixelpost_db_prefix}addons` WHERE `addon_name` LIKE '%_defensio/%' and `type`='admin'") or
        die(mysql_error());
    $defensio_admin = mysql_fetch_array($defensio_result);
    if ($defensio_admin['status'] == 'on') {
        $defensio_result = mysql_query("UPDATE {$pixelpost_db_prefix}addons SET status = 'off'WHERE `addon_name` LIKE '%_defensio/%' and `type`='admin'") or
            die(mysql_error());
    }
}

/**
 * pixelpostaddons_return_addonpath()
 * 
 * @param mixed $file
 * @return
 */
function pixelpostaddons_return_addonpath($file)
{
    $filename = basename($file, ".php");
    $query = "SELECT `addon_name` FROM `{$pixelpost_db_prefix}addons` WHERE `addon_name` LIKE '%" .
        $filename . "%'";
    $defensio_result = mysql_query($query) or die(mysql_error());
    while ($row = mysql_fetch_array($defensio_result)) {
        $addon_path = $row[0];
    }
    $pos = strpos($addon_path, "/");
    if ($pos === false) {
        $addon_path = null;
    } else {
        $addon_path = substr($addon_path, 0, $pos);
    }
    return $addon_path;
}

/**
 * defensio_get_datetime_post()
 * 
 * @param mixed $parent_id
 * @return
 */
function defensio_get_datetime_post($parent_id)
{
    global $pixelpost_db_prefix;
    /**
     * Try to get the correct datetime for an blogpost
     */
    $query = "SELECT UNIX_TIMESTAMP(`datetime`) FROM `{$pixelpost_db_prefix}pixelpost` WHERE `id` = '" .
        $parent_id . "'";
    $defensio_result = mysql_query($query) or die(mysql_error());
    if (mysql_num_rows($defensio_result) == 1) {
        // check comment
        while ($row = mysql_fetch_array($defensio_result)) {
            $article_date = gmdate("Y-m-d", $row[0]);
        }
    } else {
        // trying to comment on a non-existent blog post
        header("HTTP/1.0 404 Not Found");
        header("Status: 404 File Not Found!");
        echo "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\"><HTML><HEAD>\n<TITLE>404 Not Found</TITLE>\n</HEAD><BODY>\n<H1>Not Found</H1>\nThe comment could not be accepted because the blogpost doesn't exists.<P>\n<P>Additionally, a 404 Not Found error was encountered while trying to use an ErrorDocument to handle the request.\n</BODY></HTML>";
        exit;
    }
    return $article_date;
}

/**
 * defensio_settings()
 * 
 * @return
 */
function defensio_settings()
{
    global $pixelpost_db_prefix, $cfgrow, $defensio_conf;
    // get the settings
    $defensio_result = mysql_query("SELECT * FROM `{$pixelpost_db_prefix}defensio` LIMIT 1") or
        die(mysql_error());
    $defensio_conf = mysql_fetch_array($defensio_result);
    $defensio = new Defensio($defensio_conf['key']);
    $v = array();
    $v['key'] = $defensio_conf['key'];
    if (array_shift($defensio->getUser()) == 200)
        $v['valid'] = true;
    else
        $v['valid'] = false;
    $v['threshold'] = $defensio_conf['threshold'] * 100;
    $v['remove_older_than'] = $defensio_conf['remove_older_than'];
    $v['remove_older_than_days'] = $defensio_conf['remove_older_than_days'];
    $v['defensio_widget_image'] = $defensio_conf['defensio_widget_image'];
    $v['defensio_widget_align'] = $defensio_conf['defensio_widget_align'];

    if (($_GET['optionsview'] == 'antispam') && ($_GET['optaction'] ==
        'updateantispam')) {
        $older_than_error = '';
        $minimum_days = 15;
        if (isset($_POST['defensio_remove_older_than_days']) and ((int)$_POST['defensio_remove_older_than_days'] <
            $minimum_days)) {
            $older_than_error = 'Days has to be a numeric value greater than ' . $minimum_days;
            $v['remove_older_than_days'] = $minimum_days;
        } else {
            $v['remove_older_than_days'] = (int)$_POST['defensio_remove_older_than_days'];
        }
        $v['key'] = mysql_real_escape_string(trim($_POST['new_key']));
        $defensio = new Defensio($v['key']);
        if (array_shift($defensio->getUser()) == 200)
            $v['valid'] = true;
        else
            $v['valid'] = false;
        $v['threshold'] = ((int)$_POST['new_threshold']);
        $v['defensio_widget_image'] = $_POST['defensio_widget_image'];
        $v['defensio_widget_align'] = $_POST['defensio_widget_align'];
        $v['remove_older_than_error'] = $older_than_error;
        if ($_POST['defensio_remove_older_than'] == 'on') {
            $v['remove_older_than'] = 1;
        } else {
            $v['remove_older_than'] = 0;
        }
        $threshold_db = $v['threshold'] / 100;
        $query = "UPDATE {$pixelpost_db_prefix}defensio 
            SET `threshold` = '" . $threshold_db . "', 
            `remove_older_than` = '" . $v['remove_older_than'] . "', 
            `remove_older_than_days` = '" . $v['remove_older_than_days'] . "',
            `key` = '" . $v['key'] . "',
            `defensio_widget_image` = '" . $v['defensio_widget_image'] . "',
            `defensio_widget_align` = '" . $v['defensio_widget_align'] . "'";
        $defensio_result = mysql_query($query) or die(mysql_error());
    }
    echo "<div class='jcaption'>DEFENSIO settings</div>
		<div class='content'>
		<a href=\"http://defensio.com\">Defensio</a>'s blog spam web service aggressively and intelligently prevents comment and trackback spam from hitting your blog. You should quickly notice a dramatic reduction in the amount of spam you have to worry about.
  		<p>When the filter does rarely make a mistake (say, the odd spam message gets through or a rare good comment is marked as spam) we've made it a joy to sort through your comments and set things straight. Not only will the filter learn and improve over time, but it will do so in a personalized way!</p>
  		<p>In order to use our service, you will need a <strong>free</strong> Defensio API key.  Get yours now at <a href=\"http://defensio.com/signup\">Defensio.com</a>.</p>
  		<h3>Defensio API Key</h3>";
    echo defensio_render_key_validity($v);
    echo "<input type=\"text\" value=\"" . $v['key'] . "\" name=\"new_key\" size=\"38\" /><br />";
    echo defensio_render_spaminess_threshold_option($v['threshold']);
    echo "<h3><label>Automatic removal of spam</label></h3>";
    echo defensio_render_delete_older_than_option($v);
    echo defensio_render_widget_image_option($v);
    echo defensio_render_widget_align_option($v);
    echo "</div>";
}

/**
 * defensio_counter()
 * 
 * @param mixed $defensio_conf
 * @return
 */
function defensio_counter($defensio_conf)
{
    $defensio = new Defensio($defensio_conf['key']);
    $last_updated = $defensio_conf['defensio_stats_updated_at'];
    $two_hours = 60 * 60 * 2;

    if (($last_updated == null) or (mktime() - $last_updated > $two_hours)) {
        $stats_result = defensio_get_stats($defensio, $defensio_conf);
    } else {
        $stats_result = sxml_unserialize($defensio_conf['defensio_stats']);
    }
    if ($stats_result[1]->status = 'success') {
        switch ($defensio_conf['defensio_widget_align']) {
            case 'left':
                $counter_image_style = "float: left;";
                break;
            case 'right':
                $counter_image_style = "float: right;";
                break;
            case 'center':
                $counter_image_style = "margin: 0 auto;";
                break;
        }
        if ($defensio_conf['defensio_widget_image'] == 'dark') {
            $counter_text_style = "color: #fff;";
        } else {
            $counter_text_style = "color:#211d1e;";
        }
        $unwanted = $stats_result[1]->unwanted;
        $spam = (int)$unwanted->spam;
        $counter_html = '
		<div id="defensio_counter" style="width: 100%; margin: 10px 0 10px 0; text-align: ' .
            $defensio_conf['defensio_widget_align'] . '">
			<a id="defensio_counter_link" style ="text-decoration: none;" href="http://defensio.com">
				<div id="defensio_counter_image" style="background:url(\'addons/_defensio2.0/images/defensio-counter-' .
            $defensio_conf['defensio_widget_image'] . '.gif\') no-repeat top left; border:none; font: 10px \'Trebuchet MS\', \'Myriad Pro\', sans-serif; overflow: hidden; text-align: left; height: 50px; width: 120px;' .
            $counter_image_style . '">
					<div style="display:block; width: 100px; padding: 9px 9px 25px 12px; line-height: 1em; color: #211d1e;' .
            $counter_text_style . '" "><div style="font-size: 12px; font-weight: bold;">' .
            $spam . '</div> spam comments blocked</div>
				</div>
				<div style="clear:both;" class="defensio_clear">&nbsp;</div>
			</a>
		</div>';
    } else {
        $counter_html = 'Error in retrieving stats.';
    }
    if ($defensio_conf['defensio_widget_image'] != 'none') {
        return $counter_html;
    } else {
        return null;
    }
}

/**
 * defensio_submit_nonspam_comment()
 * 
 * @param mixed $defensio_conf
 * @return
 */
function defensio_submit_nonspam_comment($defensio_conf)
{
    //Function to report the comment is not spam
    //Loop thru the $_POST['moderate_commnts_boxes'] and keep marking each comment as spam to Defensio
    global $pixelpost_db_prefix, $defensio;
    $defensio = new Defensio($defensio_conf['key']);
    if (is_array($_POST['moderate_commnts_boxes'])) {
        $number_of_images = count($_POST['moderate_commnts_boxes']);
        $counter = 0;
        $counter_fail = 0;
        $counter_no_signature = 0;
        $signatures = null;
        foreach ($_POST['moderate_commnts_boxes'] as $cid) {
            $query = "SELECT `signature` FROM {$pixelpost_db_prefix}comments WHERE id = '" . (int)
                $cid . "'";
            $defensio_result = mysql_query($query);
            if (mysql_num_rows($defensio_result)) {
                $row = mysql_fetch_assoc($defensio_result);
                if ($row['signature'] != null) {
                    $put_result = $defensio->putDocument($row['signature'], array('allow' => 'true'));
                    if ($put_result[0] == 200) {
                        $counter = $counter + 1;
                        //Since comment is not spam, let's mark it to publish
                        $query = "UPDATE {$pixelpost_db_prefix}comments SET publish = 'yes' WHERE id = '" . (int)
                            $cid . "'";
                        mysql_query($query);
                    } else {
                        $counter_fail = $counter_fail + 1;
                    }
                } else {
                    // no signature was found. With the addition of code in the frontpage addon
                    // this situation should almost never happen. However, if it does we'll send
                    // the comment to Defensio again.
                    $counter_no_signature = $counter_no_signature + 1;
                    $document = array('client' => 'Pixelpost Defensio Addon | ' . $addon_version .
                        ' | Schonhose | schonhose@pixelpost.org', 'content' => $row['message'],
                        'platform' => 'pixelpost', 'type' => 'comment', 'async' => 'true',
                        'async-callback' => $defensio_conf['blog'] .
                        'addons/_defensio2.0/lib/callback.php?id=' . md5($defensio_conf['key']),
                        'author-email' => $row['email'], 'author-ip' => $row['ip'], 'author-logged-in' =>
                        'false', 'author-name' => $row['name'], 'parent-document-date' =>
                        defensio_get_datetime_post($row['parent_id']), 'parent-document-permalink' => $defensio_conf['blog'] .
                        "index.php?showimage=" . $row['parent_id'], 'referrer' => $_SERVER['HTTP_REFERER']);
                    $post_result = $defensio->postDocument($document);
                    defensio_process_comment_pixelpost($post_result, true, (int)$cid);
                }
            }
        }
        // construct the error message
        $GLOBALS['defensio_result_message'] = '<div class="jcaption confirm">';
        if ($counter > 0) {
            $GLOBALS['defensio_result_message'] .= 'Reported ' . $counter .
                ' comments as HAM to Defensio.';
            if ($counter_no_signature > 0) {
                $GLOBALS['defensio_result_message'] .= 'However, ' . $counter_no_signature .
                    'comments could not be reported.';
            }
        } else {
            $GLOBALS['defensio_result_message'] .= 'Could not report ' . $counter_fail .
                ' comments as HAM to Defensio.';
        }
        $GLOBALS['defensio_result_message'] .= '</div>';
    } else {
        $GLOBALS['defensio_result_message'] =
            '<div class="jcaption confirm">You must select at least one comment.</div>';
    }
}

/**
 * defensio_submit_spam_comment()
 * 
 * @param mixed $defensio_conf
 * @return
 */
function defensio_submit_spam_comment($defensio_conf)
{
    //Function to report the comment as spam which Defensio marked as not spam
    //Loop thru the $_POST['moderate_commnts_boxes'] and keep marking each comment as spam to Defensio
    global $pixelpost_db_prefix, $defensio;
    $defensio = new Defensio($defensio_conf['key']);
    if (is_array($_POST['moderate_commnts_boxes'])) {
        $number_of_images = count($_POST['moderate_commnts_boxes']);
        $counter = 0;
        $counter_fail = 0;
        $counter_no_signature = 0;
        $signatures = array();
        foreach ($_POST['moderate_commnts_boxes'] as $cid) {
            $query = "SELECT * FROM {$pixelpost_db_prefix}comments WHERE id = '" . (int)$cid .
                "'";
            $defensio_result = mysql_query($query) or die(mysql_error());
            if (mysql_num_rows($defensio_result)) {
                $row = mysql_fetch_assoc($defensio_result);
                if ($row['signature'] != null) {
                    $put_result = $defensio->putDocument($row['signature'], array('allow' => 'false'));
                    if ($put_result[0] == 200) {
                        $counter = $counter + 1;
                        //Since comment is spam, let's mark it as marked by defensio
                        $query = "UPDATE {$pixelpost_db_prefix}comments SET publish = 'dfn' WHERE id = '" . (int)
                            $cid . "'";
                        mysql_query($query);
                    } else {
                        $counter_fail = $counter_fail + 1;
                    }
                } else {
                    // no signature was found. With the addition of code in the frontpage addon
                    // this situation should almost never happen. However, if it does we'll send
                    // the comment to Defensio again.
                    $counter_no_signature = $counter_no_signature + 1;
                    $document = array('client' => 'Pixelpost Defensio Addon | ' . $addon_version .
                        ' | Schonhose | schonhose@pixelpost.org', 'content' => $row['message'],
                        'platform' => 'pixelpost', 'type' => 'comment', 'async' => 'true',
                        'async-callback' => $defensio_conf['blog'] .
                        'addons/_defensio2.0/lib/callback.php?id=' . md5($defensio_conf['key']),
                        'author-email' => $row['email'], 'author-ip' => $row['ip'], 'author-logged-in' =>
                        'false', 'author-name' => $row['name'], 'parent-document-date' =>
                        defensio_get_datetime_post($row['parent_id']), 'parent-document-permalink' => $defensio_conf['blog'] .
                        "index.php?showimage=" . $row['parent_id'], 'referrer' => $_SERVER['HTTP_REFERER']);
                    $post_result = $defensio->postDocument($document);
                    defensio_process_comment_pixelpost($post_result, true, (int)$cid);
                }
            }
        }
        // construct the error message
        $GLOBALS['defensio_result_message'] = '<div class="jcaption confirm">';
        if ($counter > 0) {
            $GLOBALS['defensio_result_message'] .= 'Reported ' . $counter .
                ' comments as SPAM to Defensio.';
            if ($counter_no_signature > 0) {
                $GLOBALS['defensio_result_message'] .= 'However, ' . $counter_no_signature .
                    'comments could not be reported.';
            }
        } else {
            $GLOBALS['defensio_result_message'] .= 'Could not report ' . $counter_fail .
                ' comments as SPAM to Defensio.';
        }
        $GLOBALS['defensio_result_message'] .= '</div>';
    } else {
        $GLOBALS['defensio_result_message'] =
            '<div class="jcaption confirm">You must select at least one comment.</div>';
    }
}

/**
 * defensio_process_unprocessed()
 * 
 * @param mixed $defensio_conf
 * @return
 */
function defensio_process_unprocessed($defensio_conf)
{
    global $pixelpost_db_prefix, $defensio;
    //There are three possibilities: it can have failed, it is pending or somehow the status is null
    //For each step there is a different approach.
    $defensio = new Defensio($defensio_conf['key']);
    $seconds = 1209600; //86399 is 24 hours, but in this case we approximately 14 days.
    // 1) first get all the comments that have failed or have status null for the last 2 weeks and process them again.
    $query = "SELECT *
        FROM `{$pixelpost_db_prefix}comments`
        WHERE (
            (
                `status` = 'fail'
                OR `status` IS NULL
            )
            AND (
                UNIX_TIMESTAMP( `datetime` )
                BETWEEN UNIX_TIMESTAMP( DATE_ADD( CURDATE( ) , INTERVAL - ".$seconds."
                SECOND ) )
                AND UNIX_TIMESTAMP( DATE_ADD( CURDATE( ) , INTERVAL +86400
                SECOND ) )
            )
        )";
    $defensio_result = mysql_query($query) or die(mysql_error());
    while ($row = mysql_fetch_array($defensio_result)) {
        $document = array('client' => 'Pixelpost Defensio Addon | ' . $addon_version .
            ' | Schonhose | schonhose@pixelpost.org', 'content' => $row['message'],
            'platform' => 'pixelpost', 'type' => 'comment', 'async' => 'true',
            'async-callback' => $defensio_conf['blog'] .
            'addons/_defensio2.0/lib/callback.php?id=' . md5($defensio_conf['key']),
            'author-email' => $row['email'], 'author-ip' => $row['ip'], 'author-logged-in' =>
            'false', 'author-name' => $row['name'], 'parent-document-date' =>
            defensio_get_datetime_post($row['parent_id']), 'parent-document-permalink' => $defensio_conf['blog'] .
            "index.php?showimage=" . $row['parent_id'], 'referrer' => $_SERVER['HTTP_REFERER']);
        /**
         * Only continue with Defensio if the API key is valid
         */
        if (array_shift($defensio->getUser()) == 200) {
            $post_result = $defensio->postDocument($document);
            // we always do a NEW request here.
            defensio_process_comment_pixelpost($post_result, true, $row['id']);
        } else {
            die("The API key is invalid!!! Bye Bye.");
        }
    }
    // 2) get the pending comments. But those are a bit tricky: depending on the date we either have to GET
    // results or process them again.
    $query = "SELECT * FROM `{$pixelpost_db_prefix}comments` WHERE `status` = 'pending'";
    $defensio_result = mysql_query($query) or die(mysql_error());
    while ($row = mysql_fetch_array($defensio_result)) {
        $document = array('client' => 'Pixelpost Defensio Addon | ' . $addon_version .
            ' | Schonhose | schonhose@pixelpost.org', 'content' => $row['message'],
            'platform' => 'pixelpost', 'type' => 'comment', 'async' => 'true',
            'async-callback' => $defensio_conf['blog'] .
            'addons/_defensio2.0/lib/callback.php?id=' . md5($defensio_conf['key']),
            'author-email' => $row['email'], 'author-ip' => $row['ip'], 'author-logged-in' =>
            'false', 'author-name' => $row['name'], 'parent-document-date' =>
            defensio_get_datetime_post($row['parent_id']), 'parent-document-permalink' => $defensio_conf['blog'] .
            "index.php?showimage=" . $row['parent_id'], 'referrer' => $_SERVER['HTTP_REFERER']);
        /**
         * Only continue with Defensio if the API key is valid
         */
        if (array_shift($defensio->getUser()) == 200) {
            // here is the magic to decide if we need to GET or process
            // if the difference is less than thirty days we can still get it from Defensio
            // if it is more, then reprocess the comment.
            $no_days = floor((time() - strtotime($row['datetime'])) / 86400);
            if ($no_days < 30) {
                $get_result = $defensio->getDocument($row['signature']);
                // we always try to get the results here.
                defensio_process_comment_pixelpost($get_result, false);
            } else {
                $post_result = $defensio->postDocument($document);
                // we always do a NEW request here.
                defensio_process_comment_pixelpost($post_result, true, $row['id']);
            }
        } else {
            die("The API key is invalid!!! Bye Bye.");
        }
    }
    $defensio_comments_processed_at = mktime();
    mysql_query("UPDATE " . $pixelpost_db_prefix .
        "defensio SET defensio_comments_processed_at='" . $defensio_comments_processed_at .
        "'");
}


/**
 * defensio_render_spaminess_threshold_option()
 * 
 * @param mixed $threshold
 * @return
 */
function defensio_render_spaminess_threshold_option($threshold)
{
    $threshold_values = array(50, 60, 70, 80, 90, 95);
    echo "<h3><label for=\"new_threshold\">Obvious Spam Threshold</label></h3>
		<p>Hide comments with a spaminess higher than&nbsp;
  		<select name=\"new_threshold\" >";
    foreach ($threshold_values as $val) {
        if ($val == $threshold) {
            echo "<option selected=\"1\" >" . $val . "</option>";
        } else {
            echo "<option>" . $val . "</option>";
        }
    }
    echo "</select></p>
	<p>Any comments calculated to be above or equal to this \"spaminess\" threshold will be hidden from view in your quarantine.</p>";
}

/**
 * defensio_render_delete_older_than_option()
 * 
 * @param mixed $v
 * @return
 */
function defensio_render_delete_older_than_option($v)
{
    echo "<p>";
    if ($v['remove_older_than_error']) {
        echo "<div style=\"color:red\">";
        echo $v['remove_older_than_error'];
        echo "</div>";
    }
    echo "<input type=\"checkbox\" name=\"defensio_remove_older_than\"";
    if ($v['remove_older_than'] == 1) {
        echo ' checked ';
    }
    echo "size=\"3\" maxlength=\"3\"/>   
		Automatically delete spam for posts older than <input type=\"text\" name=\"defensio_remove_older_than_days\" value=\"" .
        $v['remove_older_than_days'] . "\" size=\"3\" maxlength=\"3\"/> days.</p>";
}

/**
 * defensio_render_widget_image_option()
 * 
 * @param mixed $v
 * @return
 */
function defensio_render_widget_image_option($v)
{
    echo "<h3><label for=\"new_threshold\">Frontpage Image</label></h3>
		<p>Select the frontpage image &nbsp;
  		<select name=\"defensio_widget_image\" >";
    if ($v['defensio_widget_image'] == 'none') {
        echo "<option value=\"none\" selected=\"yes\">none</option>";
    } else {
        echo "<option value=\"none\">none</option>";
    }
    if ($v['defensio_widget_image'] == 'light') {
        echo "<option value=\"light\" selected=\"yes\">light</option>";
    } else {
        echo "<option value=\"light\">light</option>";
    }
    if ($v['defensio_widget_image'] == 'dark') {
        echo "<option value=\"dark\" selected=\"yes\">dark</option>";
    } else {
        echo "<option value=\"dark\" >dark</option>";
    }
    echo "</select>";
}

/**
 * defensio_render_widget_align_option()
 * 
 * @param mixed $v
 * @return
 */
function defensio_render_widget_align_option($v)
{
    echo "<h3><label for=\"new_threshold\">Frontpage Image Alignment</label></h3>
		<p>Set the frontpage image alignment &nbsp;
  		<select name=\"defensio_widget_align\" >";
    if ($v['defensio_widget_align'] == 'left') {
        echo "<option value=\"left\" selected=\"yes\">left</option>";
    } else {
        echo "<option value=\"left\">left</option>";
    }
    if ($v['defensio_widget_align'] == 'right') {
        echo "<option value=\"right\" selected=\"yes\">right</option>";
    } else {
        echo "<option value=\"right\">right</option>";
    }
    if ($v['defensio_widget_align'] == 'center') {
        echo "<option value=\"center\" selected=\"yes\">center</option>";
    } else {
        echo "<option value=\"center\" >center</option>";
    }
    echo "</select>";
}

/**
 * defensio_render_key_validity()
 * 
 * @param mixed $v
 * @return
 */
function defensio_render_key_validity($v)
{
    if ($v['valid']) {
        echo "<p style=\"padding: .5em; background-color: #2d2; color: #fff;font-weight: bold;width:250px;\">This key is valid.</p>";
    } else {
        echo "<p style=\"padding: .5em; background-color: #d22; color: #fff; font-weight: bold;width:250px;\">The key you entered is invalid.</p>";
    }
}

/**
 * get_defensio_pages()
 * 
 * @return
 */
function get_defensio_pages()
{
    global $moderate_where, $moderate_where2, $order_by, $pixelpost_db_prefix;
    global $comment_row_class;
    if (!isset($_SESSION['defensio_hide_very_spam'])) {
        $_SESSION['defensio_hide_very_spam'] = "checked";
    }
    if (isset($_POST['defensio_hide_very_spam']) && (int)$_POST['defensio_hide_very_spam'] ==
        1) {
        $_SESSION['defensio_hide_very_spam'] = "checked";
    } elseif (isset($_POST['dummy'])) {
        $_SESSION['defensio_hide_very_spam'] = "";
    }
    if (isset($_GET['commentsview']) && $_GET['commentsview'] == 'defensio') {
        $defensio_conf = $GLOBALS['defensio_conf'];
        $moderate_where = " and publish='dfn' AND `spaminess` < '" . $defensio_conf['threshold'] .
            "' ";
        $moderate_where2 = " WHERE publish='dfn' AND `spaminess` < '" . $defensio_conf['threshold'] .
            "' ";
        $order_by = " ORDER BY spaminess ASC, datetime DESC ";
        if ($_SESSION['defensio_hide_very_spam'] != "checked") {
            $moderate_where = " and publish='dfn' ";
            $moderate_where2 = " WHERE publish='dfn' ";
        }
    }

}

/**
 * defensio_class_for_spaminess()
 * 
 * @param mixed $spaminess
 * @return
 */
function defensio_class_for_spaminess($spaminess)
{
    if ($spaminess < 0)
        return 'not_checked';
    elseif ($spaminess <= 0.55)
        return 'spam0';
    elseif ($spaminess <= 0.65)
        return 'spam1';
    elseif ($spaminess <= 0.70)
        return 'spam2';
    elseif ($spaminess <= 0.75)
        return 'spam3';
    elseif ($spaminess <= 0.80)
        return 'spam4';
    elseif ($spaminess <= 0.85)
        return 'spam5';
    elseif ($spaminess <= 0.90)
        return 'spam6';
    elseif ($spaminess <= 0.95)
        return 'spam7';
    elseif ($spaminess < 1)
        return 'spam8';
    else
        return 'spam9';
}

/**
 * get_defensio_style()
 * 
 * @return
 */
function get_defensio_style()
{
    // please this information in the <HEAD> section
    global $pixelpost_db_prefix;
    $defensio_conf = $GLOBALS['defensio_conf'];
    $query = "select count(*) as count from " . $pixelpost_db_prefix .
        "comments  WHERE publish='dfn' ";
    $defensio_result = mysql_query($query) or die(mysql_error());
    $count = mysql_fetch_array($defensio_result);
    echo '<style  type="text/css">
  .defensio_spam0 {background-color: #ffffff;border-bottom: 1px solid #ccc;}
  .defensio_spam1 {background-color: #faf0e1;}
  .defensio_spam2 {background-color: #faebd4;}
  .defensio_spam3 {background-color: #fae6c8;}
  .defensio_spam4 {background-color: #fae1bb;}
  .defensio_spam5 {background-color: #fadcaf;}
  .defensio_spam6 {background-color: #fad7a2;}
  .defensio_spam7 {background-color: #fad296;}
  .defensio_spam8 {background-color: #facd89;}
  .defensio_spam9 {background-color: #fac87d;}
  .defensio_not_checked {background-color: #a8c6ff;}
  #defensio_stats {list-style:disc;margin:0px;padding:0px;}
	#defensio_stats li {padding:0px;margin:0px 0px 5px 25px;border-bottom:0px;}
	</style>
  <script type="text/javascript" src="../addons/_defensio2.0/lib/pixelpost/domFunction.js"></script>
	<script type="text/javascript" charset="utf-8">
	var ElementReady;
	var foobar = new domFunction(function()
	{	
	// Script to make sure the function "getElementById()" will work on ALL browsers
	// Copied from: http://webbugtrack.blogspot.com/2007/08/bug-152-getelementbyid-returns.html
	if(ElementReady != true){
		//use browser sniffing to determine if IE or Opera (ugly, but required)
		var isOpera, isIE = false;
		if(typeof(window.opera) != \'undefined\'){isOpera = true;}
		if(!isOpera && navigator.userAgent.indexOf(\'Internet Explorer\')){isIE = true};
		//fix both IE and Opera (adjust when they implement this method properly)
		if(isOpera || isIE){
		  document.nativeGetElementById = document.getElementById;
		  //redefine it!
		  document.getElementById = function(id){
		    var elem = document.nativeGetElementById(id);
		    if(elem){
		      //verify it is a valid match!
		      if(elem.id == id){
		        //valid match!
		        return elem;
		      } else {
		        //not a valid match!
		        //start at one, because we know the first match, is wrong!
		        for(var i=1;i<document.all[id].length;i++){
		          if(document.all[id][i].id == id){
		            return document.all[id][i];
		          }
		        }
		      }
		    }
		    return null;
		  };
		}
		ElementReady = true;
	}
		// The actual code the makes it work:
		var defensio = document.getElementById(\'commentsDefensio\');
		var defensio_total = \'' . $count['count'] . '\';
		if(defensio){
			defensio.innerHTML = defensio.innerHTML + \' (\' + defensio_total + \')\';
		}
	}); // End Dom Ready
	</script>
	';
}

/**
 * defensio_get_stats()
 * 
 * @param mixed $defensio
 * @param mixed $defensio_conf
 * @return
 */
function defensio_get_stats($defensio, $defensio_conf)
{
    global $pixelpost_db_prefix;
    if (array_shift($defensio->getUser()) == 200) {
        $stats_result = $defensio->getBasicStats();
        if ($stats_result[0] == 200) {
            // update table
            $defensio_stats = serialize($stats_result);
            $defensio_stats_updated_at = mktime();
            mysql_query("UPDATE " . $pixelpost_db_prefix . "defensio SET defensio_stats='" .
                $defensio_stats . "', defensio_stats_updated_at='" . $defensio_stats_updated_at .
                "'");
            return $stats_result;
        } else {
            // the defensio_service might be down
            // to prevent hamering of the servers update the time minus 1 hour. This way the stats might be
            // out of sync for a maximum of one hour.
            $one_hour = 60 * 60;
            $defensio_stats_updated_at = mktime() - $one_hour;
            mysql_query("UPDATE " . $pixelpost_db_prefix .
                "defensio SET defensio_stats_updated_at='" . $defensio_stats_updated_at . "'");
            return false;
        }
    }
}

/**
 * sxml_unserialize()
 * 
 * @param mixed $str
 * @return
 */
function sxml_unserialize($str)
{
    return unserialize(str_replace(array('O:16:"SimpleXMLElement":0:{}',
        'O:16:"SimpleXMLElement":'), array('s:0:"";', 'O:8:"stdClass":'), $str));
}

/**
 * defensio_show_stats()
 * 
 * @param mixed $defensio_conf
 * @return
 */
function defensio_show_stats($defensio_conf)
{
    global $defensio;
    $last_updated = $defensio_conf['defensio_stats_updated_at'];
    $two_hours = 60 * 60 * 2;
    if (($last_updated == null) or (mktime() - $last_updated > $two_hours)) {
        $stats_result = defensio_get_stats($defensio, $defensio_conf);
    } else {
        $stats_result = sxml_unserialize($defensio_conf['defensio_stats']);
    }
    if ($stats_result[1]->status == 'success') {
        $percentage = number_format((float)$stats_result[1]->accuracy * 100, 2, '.', '');

        $stats = "<h2>Statistics</h2>
        	<div style=\"float:right;width:32%;background:url('../addons/_defensio2.0/images/chart.gif') 0 15px no-repeat;padding-left:45px;\">
			<h3 style=\"font-size:10pt;margin-bottom: 4px;margin-top:-10px;\">There's more!</h3>
        	<p style=\"margin:0px;\">For more detailed statistics (and gorgeous charts), please visit your Defensio <a href=\"http://defensio.com/manage/stats/" .
            $defensio_conf['key'] . "\" target=\"_blank\">Account Management</a> panel.</p>
        	</div>";
        if ($stats_result[1]->learning == 1) {
            $stats .= "<span style=\"clear:both;color:red;font-weight:bold;\">UNDEFINED LEARNING STATUS<br /><br /></span>";
        } else {
            $stats .= "<span style=\"clear:both;\">&nbsp;</span>";
        }
        $unwanted = $stats_result[1]->unwanted;
        $stats .= "<ul id='defensio_stats'>
      		<li class='defensio_statsline'><strong>Recent accuracy: " . $percentage .
            "%</strong></li>
      		<li class='defensio_statsline'>" . (int)$unwanted->malicious .
            " malicious</li>
            <li class='defensio_statsline'>" . (int)$unwanted->spam .
            " spam</li>
      		<li class='defensio_statsline'>" . (int)$stats_result[1]->legitimate->
            total . " legitimate comments</li>
      		<li class='defensio_statsline'>" . (int)$stats_result[1]->{
            'false-negatives'} . " false negatives (undetected spam)</li>
     		<li class='defensio_statsline'>" . (int)$stats_result[1]->{
            'false-positives'} .
            " false positives (legitimate comments identified as spam)</li>
 		   	</ul>";
    } else {
        $stats = "<p>Statistics could not be retrieved, please check back later.</p>";
    }
    return $stats;
}


/**
 * sendout_email()
 * 
 * @param mixed $comment
 * @param mixed $cfgrow
 * @return
 */
function sendout_email($comment, $cfgrow)
{
    // Include the language files
    $language_path = realpath(dirname(__FILE__).'/../../../../language');
    require("$language_path/lang-".$cfgrow['langfile'].".php");
   
    // ##########################################################################################//
    // EMAIL NOTE ON COMMENTS
    // ##########################################################################################//
    $link_to_comment = $cfgrow['siteurl'] . "index.php?showimage=" . $comment['parent_id'];
    $pixelpost_site_title = htmlspecialchars(stripslashes($cfgrow['sitetitle']),
        ENT_NOQUOTES);
    if ($cfgrow['commentemail'] == "yes") {
        if (strpos($comment['url'], 'https://') === false && strpos($comment['url'],
            'http://') === false && strlen($comment['url']) > 0)
            $comment['url'] = "http://" . $comment_url;

        $link_to_img_thumb_cmmnt = "Thumbnail Link:" . $cfgrow['siteurl'] . ltrim($cfgrow['thumbnailpath'],
            "./") . "thumb_" . $comment['image'];
        $img_thumb_cmmnt = "<img src='" . $cfgrow['siteurl'] . ltrim($cfgrow['thumbnailpath'],
            "./") . "thumb_" . $comment['image'] . "' >";
        $subject = "$pixelpost_site_title - $lang_email_notification_subject";
        $sent_date = gmdate("Y-m-d", time() + (3600 * $cfgrow['timezone']));
        $sent_time = gmdate("H:i", time() + (3600 * $cfgrow['timezone']));

        if ($cfgrow['htmlemailnote'] != 'yes') {
            // Plain text note email
            $body = $lang_email_notificationplain_pt1 . " : " . $link_to_comment . "\n\n" .
                $lang_email_notificationplain_pt2 . "\n\n" . $comment['message'] . "\n\n" . $lang_email_notificationplain_pt3 .
                " : " . $comment['name'];

            if ($comment['email'] != "")
                $body .= " - " . $comment['email'];

            $body .= "\n\n " . $lang_email_notificationplain_pt4;
            $headers = "Content-type: text/plain; charset=UTF-8\n";
            $headers .= "Content-Transfer-Encoding: 8bit\n";

            if ($comment_email != "") {
                $headers .= "From: " . $comment['name'] . "  <" . $cfgrow['email'] . ">\n";
                $headers .= "Reply-To: " . $comment['name'] . " <" . $comment['email'] . ">\n";
            } else
                $headers .= "From: PIXELPOST <" . $cfgrow['email'] . ">\n";

            $recipient_email = "admin <" . $cfgrow['email'] . ">";
        } else {
            // HTML note email
            $body = $lang_email_notification_pt1 . "<a href='" . $link_to_comment . "'>" . $link_to_comment .
                "</a><br>" . $img_thumb_cmmnt . "<br>" . $lang_email_notification_pt2 . " " . $comment['message'] .
                "<br>" . $lang_email_notification_pt3 . "<a href='" . $comment['url'] . "' >" .
                $comment['name'] . "</a>  - " . $comment['email'] . " <br>" . $lang_email_notification_pt4;

            ////////////
            $headers = 'MIME-Version: 1.0' . "\n";
            $headers .= 'Content-type: text/html; charset=UTF-8' . "\n";

            // Additional headers
            if ($comment['email'] != "")
                $headers .= "From: " . $comment['name'] . "  <" . $comment['email'] . ">\n";
            else
                $headers .= "From: PIXELPOST <" . $cfgrow['email'] . ">\n";

            $recipient_email = "admin <" . $cfgrow['email'] . ">";
        } // if (cfgrow['htmlemailnote']=='no')

        // Sending notification
       mail($recipient_email, $subject, $body, $headers);
    }
}

?>