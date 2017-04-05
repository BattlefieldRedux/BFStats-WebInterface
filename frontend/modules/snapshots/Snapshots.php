<?php
/**
 * BF2Statistics ASP Framework
 *
 * Author:       Steven Wilson
 * Copyright:    Copyright (c) 2006-2017, BF2statistics.com
 * License:      GNU GPL v3
 *
 */
use System\Collections\Dictionary;
use System\Controller;
use System\Database;
use System\Database\UpdateOrInsertQuery;
use System\IO\Directory;
use System\IO\File;
use System\IO\Path;
use System\Snapshot;
use System\View;

/**
 * Snapshots Module Controller
 *
 * @package Modules
 */
class Snapshots extends Controller
{
    /**
     * @var SnapshotsModel
     */
    protected $snapshotsModel;

    /**
     * @protocol    GET
     * @request     /ASP/snapshots
     * @output      html
     */
    public function index()
    {
        // Load model
        parent::loadModel('SnapshotsModel', 'snapshots');

        // Load view
        $view = new View('index', 'snapshots');
        $view->set('snapshots', $this->snapshotsModel->getSnapshots("unauthorized"));

        // Attach needed scripts for the form
        $view->attachScript("/ASP/frontend/js/datatables/jquery.dataTables.js");
        $view->attachScript("/ASP/frontend/modules/snapshots/js/index.js");

        // Attach needed stylesheets
        $view->attachStylesheet("/ASP/frontend/css/icons/icol16.css");

        // Send output
        $view->render();
    }

    /**
     * @protocol    POST
     * @request     /ASP/snapshots/accept
     * @output      json
     */
    public function postAccept()
    {
        // Ensure a valid action
        if ($_POST['action'] != 'process')
        {
            if (isset($_POST['ajax']))
                echo json_encode(['success' => false, 'message' => 'Invalid Action!']);
            else
                $this->index();

            return;
        }

        // Ensure we have a backup selected
        if (!isset($_POST['snapshot']))
        {
            echo json_encode(['success' => false, 'message' => 'No snapshots specified!']);
            return;
        }

        $file = Path::Combine(SYSTEM_PATH, "snapshots", "unauthorized", $_POST['snapshot'] . '.json');
        if (!File::Exists($file))
        {
            echo json_encode(['success' => false, 'message' => 'No snapshots with the filename exists: ' . $_POST['snapshot']]);
            return;
        }

        // Ensure that the directories we need are writable
        $path1 = Path::Combine(SYSTEM_PATH, "snapshots", "processed");
        $path2 = Path::Combine(SYSTEM_PATH, "snapshots", "failed");
        if (!Directory::IsWritable($path1) || !Directory::IsWritable($path2))
        {
            echo json_encode(['success' => false, 'message' => 'Not all snapshot directories are writable. Please Test your system configuration.']);
            return;
        }

        try
        {
            // Load model, and call method
            parent::loadModel('SnapshotsModel', 'snapshots');
            $this->snapshotsModel->importSnapshot($file, $message);

            // Tell the client of the success
            echo json_encode(['success' => true, 'message' => $message]);
        }
        catch (IOException $e)
        {
            $message = sprintf("Failed to process snapshot (%s)!\n\n%s", $file, $e->getMessage());
            echo json_encode(['success' => false, 'message' => $message]);
        }
        catch (Exception $e)
        {
            try
            {
                // Move snapshot to failed
                $newPath = Path::Combine(SYSTEM_PATH, "snapshots", "failed", Path::GetFilename($file));
                File::Move($file, $newPath);
            }
            catch (Exception $ex)
            {
                // ignore
            }

            // Output message
            $message = sprintf("Failed to process snapshot (%s)!\n\n%s", $file, $e->getMessage());
            echo json_encode(['success' => false, 'message' => $message]);
        }
    }

    /**
     * @protocol    POST
     * @request     /ASP/snapshots/delete
     * @output      json
     */
    public function postDelete()
    {
        // Ensure a valid action
        if ($_POST['action'] != 'delete')
        {
            if (isset($_POST['ajax']))
                echo json_encode(['success' => false, 'message' => 'Invalid Action!']);
            else
                $this->index();

            return;
        }

        // Ensure we have a backup selected
        if (!isset($_POST['snapshots']))
        {
            echo json_encode(['success' => false, 'message' => 'No snapshots specified!']);
            return;
        }

        $path = Path::Combine(SYSTEM_PATH, "snapshots", "unauthorized");

        try
        {
            foreach ($_POST['snapshots'] as $file)
            {
                $file = Path::Combine($path, $file . '.json');
                File::Delete($file);
            }

            echo json_encode(['success' => true, 'message' => 'Snapshots Removed.']);
        }
        catch (Exception $e)
        {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}