<?php
/**
 * BF2Statistics ASP Framework
 *
 * Author:       Steven Wilson
 * Copyright:    Copyright (c) 2006-2019, BF2statistics.com
 * License:      GNU GPL v3
 *
 */
use System\Collections\Dictionary;
use System\IO\Directory;
use System\IO\File;
use System\IO\Path;
use System\Snapshot;
use System\Text\StringHelper;

/**
 * Snapshots Model
 *
 * @package Models
 * @subpackage Snashots
 */
class SnapshotsModel
{
    /**
     * @var \System\Database\DbConnection The stats database connection
     */
    public $pdo;

    /**
     * SnapshotsModel constructor.
     */
    public function __construct()
    {
        // Fetch database connection
        $this->pdo = System\Database::GetConnection('stats');
    }

    /**
     * Fetches an array of all un-authorized snapshots, and returns a data array
     * of information about the snapshot.
     *
     * @param string $folder The snapshot folder name
     *
     * @return array
     *
     * @throws DirectoryNotFoundException thrown if the snapshot folder does not exist.
     * @throws IOException thrown if there is an error opening a snapshot file.
     */
    public function getSnapshots($folder)
    {
        // Get snapshots
        $path = Path::Combine(SYSTEM_PATH, "snapshots", $folder);
        $files = Directory::GetFiles($path, '.*\.json');

        // Create objects
        $snapshots = [];
        foreach ($files as $file)
        {
            // Open snapshot file, and grab its JSON
            $stream = File::OpenRead($file);
            $json = json_decode($stream->readToEnd(), true);
            $stream->close();

            // Ensure the JSON is valid
            if ($json != null)
            {
                $snapshot = new Dictionary(true, $json);
                $snapshots[] = [
                    'name' => Path::GetFilenameWithoutExtension($file),
                    'authid' => $snapshot['authId'],
                    'server' => $snapshot['serverName'],
                    'port' => $snapshot['gamePort'],
                    'ipaddress' => $snapshot['serverIp'],
                    'map' => $snapshot['mapName'],
                    'players' => count($snapshot['players']),
                    'date' => date('M j, Y G:i T', (int)$snapshot['mapEnd'])
                ];
            }
        }

        return $snapshots;
    }

    /**
     * Fetches an array of all failed snapshots from the database, and returns a data array
     * of information about the snapshot.
     *
     * @return array
     */
    public function getFailedSnapshots()
    {
        $query = <<<SQL
SELECT s.*, s2.id AS `server_id`, s2.name AS `server_name` 
FROM `failed_snapshot` AS `s`
  JOIN `server` AS `s2` on s.server_id = s2.id
ORDER BY s.id ASC
SQL;

        $snapshots = $this->pdo->query($query)->fetchAll();
        if (empty($snapshots))
            return [];

        return $snapshots;
    }

    /**
     * Deletes a failed snapshot record from the database, as well as the
     * snapshot file
     *
     * @param int $id
     *
     * @return bool
     */
    public function deleteFailedSnapshotById($id)
    {
        // Sanitize
        $id = (int)$id;

        // Grab failed snapshot first
        $query = "SELECT `filename` FROM `failed_snapshot` WHERE id=". $id;
        $snapshot = $this->pdo->query($query)->fetch();
        if (empty($snapshot))
            return false;

        // Delete file
        File::Delete(Path::Combine(SYSTEM_PATH, 'snapshots', 'failed', $snapshot['filename'] . '.json'));

        // Delete record
        return $this->pdo->delete('failed_snapshot', ['id' => $id]);
    }

    /**
     * Imports a snapshot, adding the server to the server table if it does not exist,
     * otherwise authorizing the server to post snapshots.
     *
     * After the snapshot is parsed, it will be moved to the 'processed' snapshot directory.
     *
     * @param string $file The full file path to the snapshot json file
     * @param bool $ignoreAuthorization if true, all security and authorization protocols will be skipped.
     *      Should always remain false unless an administrator OK's the snapshot for manual processing.
     * @param string $message [Reference Variable] Gets the result message
     *
     * @return void
     *
     * @throws Exception thrown if there is an error parsing the JSON content, or if
     *  the snapshot data is incomplete.
     * @throws IOException thrown if there is a problem moving the snapshot file to the
     *  processed folder.
     */
    public function importSnapshot($file, $ignoreAuthorization, &$message)
    {
        // Parse snapshot data
        $stream = File::OpenRead($file);
        $json = $stream->readToEnd();
        $data = json_decode($json, true);
        $stream->close();

        // Ensure we can parse json
        if ($data == null)
        {
            $code = json_last_error();
            switch ($code)
            {
                case JSON_ERROR_NONE:
                    $message = 'No errors';
                    break;
                case JSON_ERROR_DEPTH:
                    $message = 'Maximum stack depth exceeded';
                    break;
                case JSON_ERROR_STATE_MISMATCH:
                    $message = 'Underflow or the modes mismatch';
                    break;
                case JSON_ERROR_CTRL_CHAR:
                    $message = 'Unexpected control character found';
                    break;
                case JSON_ERROR_SYNTAX:
                    $message = (strpos($data, '\mapname\\') !== false)
                        ? 'Detected old SNAPSHOT format'
                        : 'Syntax error, malformed JSON';
                    break;
                case JSON_ERROR_UTF8:
                    $message = 'Malformed UTF-8 characters, possibly incorrectly encoded';
                    break;
                default:
                    $message = 'Unknown error';
                    break;
            }

            // Create Message
            $string = new \System\Text\StringBuilder();
            $string->appendLine("Unable to decode json from snapshot!");
            $string->appendLine();
            $string->appendLine("Error Message: " . $message);
            $string->appendLine("Error Code: " . $code);
            $string->appendLine("Snapshot: " . $file);
            throw new Exception($string->toString());
        }

        // Create snapshot
        $data = new Dictionary(false, $data);
        $snapshot = new Snapshot($data);

        // Ensure snapshot is not already processed from before!
        if ($snapshot->isProcessed())
        {
            $message = "Snapshot was already processed.";
        }
        else
        {
            try
            {
                // Process data
                $snapshot->processData($ignoreAuthorization);
                $message = "Snapshot was processed successfully.";
            }
            catch (Exception $e)
            {
                // Log into the database
                if ($snapshot->serverId > 0)
                {
                    $this->pdo->insert('failed_snapshot', [
                        'server_id' => $snapshot->serverId,
                        'timestamp' => time(),
                        'filename' => Path::GetFilenameWithoutExtension($snapshot->getFilename()),
                        'reason' => StringHelper::SubStrWords($e->getMessage(), 128)
                    ]);
                }

                throw $e;
            }
        }

        /**
         * Move file. Use snapshot's getFilename() in case this import was planted by an admin,
         * which was created locally on the bf2 servers snapshot path. Having the correct filename
         * is important for the /roundinfo/view/ ASP page.
         */
        $newPath = Path::Combine(SYSTEM_PATH, "snapshots", "processed", $snapshot->getFilename());
        File::Move($file, $newPath);
    }
}