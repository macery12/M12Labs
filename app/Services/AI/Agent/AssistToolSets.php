<?php

namespace Everest\Services\AI\Agent;

use Everest\Services\AI\Tools\Definitions\AdminTools;
use Everest\Services\AI\Tools\Definitions\ServerTools;

/**
 * What the assistant is *offered* while a delegated session is open.
 *
 * The authority itself belongs to core — see
 * {@see \Everest\Services\Access\DelegatedGrant}. This is the other half of what
 * used to be one class: the tool names are named explicitly rather than derived
 * from the ability list, because the two are different kinds of statement. The
 * ability list is the boundary; this is only what gets advertised. A tool
 * offered without its ability is refused at dispatch, which is the correct way
 * round, and is why these can be maintained separately without becoming a hole.
 *
 * Moves with the AI module when it becomes an extension; the grant does not.
 */
final class AssistToolSets
{
    /**
     * Tools offered while a read-only session is open.
     */
    public const READ = [
        ServerTools::DIAGNOSTIC_SNAPSHOT,
        'server_status',
        'activity_recent',
        'startup_list',
        'files_list',
        'files_read',
        'minecraft_server_info',
        'mods_installed',
    ];

    /**
     * Tools escalation adds.
     */
    public const WRITE = [
        'files_write',
        'startup_set',
        'startup_image_set',
        'server_power',
        'console_send',
    ];

    /**
     * The admin tools that stay on offer while a session is open.
     *
     * The rest of the panel-wide base set is dropped for the duration. Not for
     * safety — an administrator's capabilities are unchanged — but for room: the
     * offered set is capped, and a turn spent diagnosing one server has no use
     * for the overview or the activity feed, while it very much has a use for
     * the record of this server and of the person who reported it.
     */
    public const COMPANION = [
        AdminTools::TICKET_CONTEXT,
        'admin_server_view',
        'admin_user_view',
        'admin_ticket_view',
        'admin_ticket_messages',
    ];

    /**
     * What survives escalation. The server side grows when a session becomes
     * writable and the offered set is capped, so panel records give way: by
     * approval time the ticket, customer and server record are already in the
     * transcript. What is worth re-reading mid-fix is the one thing the panel
     * cannot reconstruct — what the customer actually said.
     */
    public const WRITABLE_COMPANION = [
        'admin_ticket_messages',
    ];

    /**
     * The tools a session at this level advertises.
     *
     * @return string[]
     */
    public static function for(bool $writable): array
    {
        return $writable ? array_merge(self::READ, self::WRITE) : self::READ;
    }
}
