<?php
include_once("Lighthouse.php");
include_once("DoneDone.php");
class Migrator
{
    protected $lighthouse;
    protected $donedone;
    protected $migrationDatabase;
    protected $syncedProjectsID;
    protected $syncedUsersID;

    function __construct($lighthouseDomain, $lighthouseLogin, $lighthousePassword, $donedoneDomain, $donedoneLogin, $donedonePassword, $donedoneAPItoken)
    {
        $this->lighthouse = new LighthouseIssueTracker($lighthouseDomain, $lighthouseLogin, $lighthousePassword);
        $this->donedone = new IssueTracker($donedoneDomain, $donedoneAPItoken,$donedoneLogin, $donedonePassword);

        try
        {
            if ($this->migrationDatabase = new SQLite3('Database/migrator.db'))
            {
                $this->migrationDatabase->query("CREATE TABLE IF NOT EXISTS Users (lighthouseUserID INTEGER PRIMARY KEY,
                                                                                   userName TEXT,
                                                                                   donedoneUserID TEXT)");
                $this->migrationDatabase->query("CREATE TABLE IF NOT EXISTS Projects (lighthouseProjectID INTEGER PRIMARY KEY,
                                                                                      projectName TEXT,
                                                                                      donedoneProjectID TEXT)");
                $this->migrationDatabase->query("CREATE TABLE IF NOT EXISTS Tickets (lighthouseTicketID INTEGER,
                                                                                     lighthouseProjectID INTEGER,
                                                                                     title TEXT,
                                                                                     body TEXT,
                                                                                     creatorName TEXT,
                                                                                     assignedUserName TEXT,
                                                                                     creationDate TEXT,
                                                                                     state TEXT,
                                                                                     tag TEXT,
                                                                                     version TEXT,
                                                                                     successfullyCreated INT,
                                                                                     successfullyAttached INT,
                                                                                     successfullyUpdated INT,
                                                                                     successfullyCommented INT,
                                                                                     donedoneID TEXT,
                                                                                     PRIMARY KEY (lighthouseTicketID, lighthouseProjectID))");
                $this->migrationDatabase->query("CREATE TABLE IF NOT EXISTS Versions (lighthouseTicketID INTEGER,
                                                                                      lighthouseProjectID INTEGER,
                                                                                      versionNumber TEXT,
                                                                                      comment TEXT,
                                                                                      PRIMARY KEY (lighthouseTicketID, lighthouseProjectID, versionNumber))");
                $this->migrationDatabase->query("CREATE TABLE IF NOT EXISTS Attachments (lighthouseTicketID INTEGER,
                                                                                         lighthouseProjectID INTEGER,
                                                                                         path TEXT,
                                                                                         PRIMARY KEY (lighthouseTicketID, lighthouseProjectID, path))");
            }
        }
        catch (Exception $e)
        {
            die($e->Message());
        }
    }
//
// Sync
    public function syncProjectsID()
    {
        $this->syncedProjectsID = array();
        $lighthouseProjects = $this->lighthouse->getAllProjects();
        $donedoneProjects = json_decode($this->donedone->getProjects(false));

        foreach($lighthouseProjects as $lightHouseIndex => $lighthouseProject)
        {
            $donedoneProjectID = '';
            foreach($donedoneProjects as $donedoneIndex => $donedoneProject)
            {
                if($lighthouseProject["name"] == $donedoneProject->Name)
                    $donedoneProjectID = $donedoneProject->ID;
            }

            $lighthouseProjectsID = $lighthouseProject["id"];
            $lighthouseProjectsName = $lighthouseProject["name"];

            $query = "INSERT INTO Projects (lighthouseProjectID, projectName, donedoneProjectID) VALUES ($lighthouseProjectsID, \"$lighthouseProjectsName\", \"$donedoneProjectID\")";
            $success = $this->migrationDatabase->query($query);
        }
    }

    public function syncUserID()
    {
        $query = "SELECT lighthouseProjectID, donedoneProjectID FROM Projects WHERE donedoneProjectID !=\"\"";
        $result = $this->migrationDatabase->query($query);
        while($value = $result->fetchArray(SQLITE3_ASSOC))
        {
            $lighthouseUsers = $this->lighthouse->getAllUsersForProject($value["lighthouseProjectID"]);
            $donedoneUsers = json_decode($this->donedone->getAllPeopleInProject($value["donedoneProjectID"]));

            foreach($lighthouseUsers as $lighthouseUser)
            {
                $donedoneUserID = '';
                foreach($donedoneUsers as $donedoneUser)
                {
                    if($lighthouseUser["name"] == $donedoneUser->Value)
                        $donedoneUserID = $donedoneUser->ID;
                }

                $lighthouseUserID = $lighthouseUser["id"];
                $lighthouseUserName = $lighthouseUser["name"];

                $query = "INSERT INTO Users (lighthouseUserID, userName, donedoneUserID) VALUES (\"$lighthouseUserID\", \"$lighthouseUserName\", \"$donedoneUserID\")";
                $success = $this->migrationDatabase->query($query);
            }
        }

    }

    public function syncAllTickets()
    {
        $query = "SELECT lighthouseProjectID, donedoneProjectID donedoneProjectID FROM Projects WHERE donedoneProjectID !=\"\"";
        $result = $this->migrationDatabase->query($query);

        while($value = $result->fetchArray(SQLITE3_ASSOC))
        {
            $this->createTicketsForProject($value["lighthouseProjectID"],$value["donedoneProjectID"]);
            $this->updateTicketsStateForProject($value["lighthouseProjectID"],$value["donedoneProjectID"]);
            $this->commentTicketsForProject($value["lighthouseProjectID"],$value["donedoneProjectID"]);
        }
    }
//
// Getters
    public function getAllTickets()
    {
        $query = "SELECT lighthouseProjectID FROM Projects WHERE donedoneProjectID !=\"\"";
        $result = $this->migrationDatabase->query($query);

        while($value = $result->fetchArray(SQLITE3_ASSOC))
            $this->getTicketsForProject($value["lighthouseProjectID"]);
    }

    public function getTicketsForProject($projectID)
    {
        $lighthouseTickets = $this->lighthouse->getAllTicketsForProject($projectID);

        foreach($lighthouseTickets as $ticketNumber => $ticket)
        {
            $tag = str_replace("\"","", $this->createDonedoneTag($ticket["tag"],$ticket["milestone"]));
            $state = $this->convertTicketState($ticket["state"]);

            $title = str_replace("\"","",$ticket["title"]);
            $body = str_replace("\"","",$ticket["originalBody"]);

            $query = "INSERT INTO Tickets (lighthouseTicketID, lighthouseProjectID,
                                           title,  body, creatorName, assignedUserName, creationDate, state, tag, version,
                                           successfullyCreated, successfullyAttached,  successfullyUpdated, successfullyCommented, donedoneID)
                                   VALUES ($ticket[number], $ticket[projectID],
                                           \"$title\", \"$body\", \"$ticket[creatorName]\", \"$ticket[assignedUserName]\", \"$ticket[creationDate]\", \"$state\", \"$tag\", $ticket[version],
                                           1, 1, 1, 1, \"\")";
            $result = $this->migrationDatabase->query($query);

            foreach($ticket["versions"] as $version)
            {
                $commentary = str_replace("\"","",$this->createCommentFromChanges($version));
                $query = "INSERT INTO Versions (lighthouseTicketID, lighthouseProjectID, versionNumber, comment)
                                        VALUES ($ticket[number], $ticket[projectID], $version[number], \"$commentary\")";
                $result = $this->migrationDatabase->query($query);
            }

            foreach($ticket["attachments"] as $attachment)
            {
                $attachmentPath = $this->lighthouse->downloadAttachment($attachment["url"],$attachment["fileName"]);
                $query = "INSERT INTO Attachments (lighthouseTicketID, lighthouseProjectID, path)
                                        VALUES ($ticket[number], $ticket[projectID], \"$attachmentPath\")";
                $result = $this->migrationDatabase->query($query);
            }
        }
    }
//
// Setters
    public function createTicketsForProject($lighthouseProjectID, $donedoneProjectID)
    {
        $query = "SELECT * FROM Tickets WHERE lighthouseProjectID = $lighthouseProjectID AND successfullyCreated > 0 ORDER BY lighthouseTicketID ASC";
        $result = $this->migrationDatabase->query($query);

        $syncedUsers = $this->getSyncedUsers();

        while($value = $result->fetchArray(SQLITE3_ASSOC))
        {
            $attachments = array();
            $query = "SELECT path FROM Attachments WHERE lighthouseTicketID = $value[lighthouseTicketID] AND $value[lighthouseProjectID]";
            $resultAttachments = $this->migrationDatabase->query($query);
            $index = 0;
            while($attachment = $resultAttachments->fetchArray(SQLITE3_ASSOC))
            {
                $attachments[$index] = $attachment["path"];
                $index++;
            }

            $tester = $syncedUsers[$value["creatorName"]];
            if($value["creatorName"] != "")
            {
                $resolver = $tester;
            }
            else
                $resolver = $syncedUsers[$value["assignedUserName"]];

            $createIssue = $this->donedone->createIssue
            (
                $donedoneProjectID,
                $value["title"],
                2,// Priority middle
                $resolver,
                $tester,
                $value["body"],
                $value["tag"],
                null,
                $attachments
            );
            $createIssue = json_decode($createIssue);
            if($createIssue->IssueID != null)
            {
                $query = "UPDATE Tickets SET successfullyCreated = 0, donedoneID = $createIssue->IssueID WHERE lighthouseTicketID = $value[lighthouseTicketID] AND $value[lighthouseProjectID]";
                $update = $this->migrationDatabase->query($query);
            }
        }
    }

    public function updateTicketsStateForProject($lighthouseProjectID, $donedoneProjectID)
    {
        $query = "SELECT * FROM Tickets WHERE lighthouseProjectID = $lighthouseProjectID AND successfullyCreated = 0 AND successfullyUpdated>0";
        $result = $this->migrationDatabase->query($query);

        while($value = $result->fetchArray(SQLITE3_ASSOC))
        {
            $updateIssue = $this->donedone->updateIssue($donedoneProjectID, $value["donedoneID"], $value["state"]);
            $updateIssue = json_decode($updateIssue);
            if($updateIssue->IssueURL != null)
            {
                $query = "UPDATE Tickets SET successfullyUpdated = 0  WHERE lighthouseTicketID = $value[lighthouseTicketID] AND $value[lighthouseProjectID]";
                $updateResult = $this->migrationDatabase->query($query);
            }
        }
    }

    public function commentTicketsForProject($lighthouseProjectID, $donedoneProjectID)
    {
        $query = "SELECT * FROM Tickets WHERE lighthouseProjectID = $lighthouseProjectID AND successfullyCreated = 0 AND successfullyUpdated = 0 AND successfullyCommented != version";
        $resultTickets = $this->migrationDatabase->query($query);

        while($ticket = $resultTickets->fetchArray(SQLITE3_ASSOC))
        {
            $query = "SELECT versionNumber, comment FROM Versions WHERE lighthouseTicketID = $ticket[lighthouseTicketID] AND lighthouseProjectID = $lighthouseProjectID ORDER BY versionNumber ASC";
            $resultVersions = $this->migrationDatabase->query($query);
            while($version = $resultVersions->fetchArray(SQLITE3_ASSOC))
            {
                if($version["versionNumber"] == ++$ticket["successfullyCommented"])
                {
                    $commentIssue = $this->donedone->createComment($donedoneProjectID, $ticket["donedoneID"], $version["comment"]);
                    $commentIssue = json_decode($commentIssue);
                    if($commentIssue->CommentURL != null)
                    {
                        $query = "UPDATE Tickets SET successfullyCommented = $ticket[successfullyCommented]  WHERE lighthouseTicketID = $ticket[lighthouseTicketID] AND $ticket[lighthouseProjectID]";
                        $commentResult = $this->migrationDatabase->query($query);
                    }
                }
            }
        }
    }
//
// ProcessData
    public function getSyncedUsers()
    {
        $query = "SELECT * FROM Users ";
        $result = $this->migrationDatabase->query($query);

        $resultUsers = array();

        while($user = $result->fetchArray(SQLITE3_ASSOC))
        {
            $resultUsers[$user["userName"]] =  $user["donedoneUserID"];
        }

        return $resultUsers;
    }

    public function convertTicketState($lighthouseState)
    {
        $donedoneStateID = "12";// Open

        if ($lighthouseState == "resolved")
            $donedoneStateID = "22";// Fixed
        elseif ($lighthouseState == "open")
            $donedoneStateID = "13";// In progress
        elseif ($lighthouseState == "invalid")
            $donedoneStateID = "14";// Not an issue

        return $donedoneStateID;
    }

// TODO: More universal tag creation
    public function createDonedoneTag($tag, $milestone)
    {
        if($tag != "")
        {
            $cleanTag = str_replace("\"","", $tag);

            $tagType = substr($cleanTag, 0, 1);
            if($tagType == "b" || $tagType == "r")
            {
                $tagComponentsArray = explode(" ", $cleanTag);
                $donedoneTag = sprintf("$tagComponentsArray[0]-$milestone-$tagComponentsArray[1]");
            }
            else
            {
                if($milestone != "")
                    $donedoneTag = sprintf("release-$milestone");
                else
                    $donedoneTag = sprintf("release-$tag");
            }
        }
        else
            $donedoneTag = sprintf("release-$milestone");

        return $donedoneTag;
    }

    public function createCommentFromChanges($versionChanges)
    {
        $comment = "";
        foreach($versionChanges  as $attribute => $value)
        {
            if($attribute == "user" && $value != null)
                $comment .= "{$versionChanges["user"]} :\n\n";
            elseif($attribute == "body" && $value != null)
                $comment .= "{$versionChanges["body"]}\n\n";
            elseif($attribute != "number" && $value != null)
                $comment .= "-> {$attribute} changed from $value[old] to $value[new]\n\n";
        }

        return $comment;
    }
}
?>