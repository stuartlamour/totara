<?php
/*
 * This file is part of Totara LMS
 *
 * Copyright (C) 2010-2012 Totara Learning Solutions LTD
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Piers Harding <piers@catalyst.net.nz>
 * @package totara
 * @subpackage message
 */

/**
 * Upgrade code for the oauth plugin
 */

function xmldb_totara_message_upgrade($oldversion) {
    global $CFG, $DB, $OUTPUT;

    $dbman = $DB->get_manager();

    $result = true;

    if ($oldversion < 2012012701) {

        // change user preferences for both tasks and alerts
        $types = array('alert' => 'totara_msg_send_alrt_emails', 'task' => 'totara_msg_send_task_emails');
        foreach ($types as $type => $oldsetting) {

            // find old settings
            $prefs = $DB->get_records('user_preferences', array('name' => $oldsetting));
            foreach ($prefs as $pref) {
                $newpref = "totara_{$type}";
                if ($pref->value == 1) {
                    $newpref .= ',email';
                }

                // set new ones
                set_user_preference("message_provider_totara_message_{$type}_loggedin", $newpref, $pref->userid);
                set_user_preference("message_provider_totara_message_{$type}_loggedoff", $newpref, $pref->userid);

                // remove the old setting
                unset_user_preference($oldsetting, $pref->userid);
            }
        }
        echo $OUTPUT->notification('Update user notification preferences', 'notifysuccess');
    }

    if ($oldversion < 2012012702) {
        //fix old 1.9 totara_msg tables

        // drop the existing message index and remove null constraint on roleid
        // needed as we reuse this table in 2.2 but roleid no longer exists (will be dropped later)
        $table = new xmldb_table('message_metadata');
        $index = new xmldb_index('role');
        $index->setUnique(true);
        $index->setFields(array('roleid', 'messageid'));
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        $field = new xmldb_field('roleid', XMLDB_TYPE_INTEGER, 10, XMLDB_UNSIGNED, null, null);
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_notnull($table, $field);
        }
        echo $OUTPUT->notification('Fix message_metadata role properties', 'notifysuccess');

        // Recreate messages in new tables
        $table = new xmldb_table('message20');
        if ($dbman->table_exists($table)) {
            require_once($CFG->dirroot.'/totara/message/messagelib.php');
            //first, simple copy of contents of message_read_20 to message_read
            $msgs = $DB->get_records('message_read20');
            foreach ($msgs as $msg) {
                unset($msg->id);
                //fix contexturl to change /local/ to /totara/ for totara modules only
                $msg->contexturl = str_replace('/local/plan','/totara/plan', $msg->contexturl);
                $msg->contexturl = str_replace('/local/program','/totara/program', $msg->contexturl);
                //1.1 bug, many messages are set as format_plain when they should be format_html
                $msg->fullmessageformat = FORMAT_HTML;
                $DB->insert_record('message_read', $msg);
            }
            //now the unread messages
            $msgs = $DB->get_records_sql('SELECT
                                    m.id,
                                    m.useridfrom,
                                    m.useridto,
                                    m.subject,
                                    m.fullmessage,
                                    m.timecreated,
                                    m.alert,
                                    d.roleid,
                                    d.msgstatus,
                                    d.msgtype,
                                    d.urgency,
                                    d.icon,
                                    d.onaccept,
                                    d.onreject,
                                    d.oninfo,
                                    m.contexturl,
                                    m.contexturlname,
                                    p.name as processor
                                    FROM {message20} m LEFT JOIN {message_metadata} d ON d.messageid = m.id
                                    LEFT JOIN {message_processors20} p on d.processorid = p.id
                                    ', array());

            // truncate the old metadata
            $DB->delete_records('message_metadata', null);

            //disable emails during the port
            $orig_emailstatus = $DB->get_field('message_processors', 'enabled', array('name' => 'email'));
            if ($orig_emailstatus == 1) {
                $DB->set_field('message_processors', 'enabled', '0', array('name' => 'email'));
            }

            $pbar = new progress_bar('migratetotaramessages', 500, true);
            $count = count($msgs);
            $i = 0;
            // now recreate the messages
            foreach ($msgs as $msg) {
                $i++;
                /* SCANMSG: need to check other messages for local/ in the contexturl */
                //fix contexturl to change /local/ to /totara/ for totara modules only
                $msg->contexturl = str_replace('/local/plan','/totara/plan', $msg->contexturl);
                $msg->contexturl = str_replace('/local/program','/totara/program', $msg->contexturl);
                $msg->userto = $DB->get_record('user', array('id' => $msg->useridto), '*', MUST_EXIST);
                $msg->userfrom = $DB->get_record('user', array('id' => $msg->useridfrom), '*', MUST_EXIST);
                //1.1 bug, many messages are set as format_plain when they should be format_html
                $msg->fullmessageformat = FORMAT_HTML;
                !empty($msg->onaccept) && $msg->onaccept = unserialize($msg->onaccept);
                !empty($msg->onreject) && $msg->onreject = unserialize($msg->onreject);
                !empty($msg->oninfo) && $msg->oninfo = unserialize($msg->oninfo);
                if ($msg->processor == 'totara_task') {
                    tm_task_send($msg);
                } else {
                    tm_alert_send($msg);
                }
                upgrade_set_timeout(60*5); // set up timeout, may also abort execution
                $pbar->update($i, $count, "Migrating totara messages - message $i/$count.");
            }
            $pbar->update($count, $count, "Migrated totara messages - done!");

            //re-enable emails if they were originally turned on
            if ($orig_emailstatus == 1) {
                $DB->set_field('message_processors', 'enabled', '1', array('name' => 'email'));
            }
            echo $OUTPUT->notification('totara/message: Recreated existing alerts and tasks ('.count($msgs).')', 'notifysuccess');
        }

        // drop tables
        $tables = array('message20', 'message_read20', 'message_working20', 'message_processors20', 'message_providers20');
        foreach ($tables as $tablename) {
            $table = new xmldb_table($tablename);
            if ($dbman->table_exists($table)) {
                $dbman->drop_table($table);
            }
        }
        echo $OUTPUT->notification('Dropping obsolete totara_msg tables', 'notifysuccess');

        // remove the roleid
        $table = new xmldb_table('message_metadata');
        $field = new xmldb_field('roleid');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }
        echo $OUTPUT->notification('Removing message_metadata roleid field', 'notifysuccess');
    }
    return $result;
}