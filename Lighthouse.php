<?php
class LighthouseIssueTracker
{
    protected $baseURL;
    public  $username;
    public $password;

    function __construct($domain, $username, $password)
    {
        $this->baseURL = "https://{$domain}.lighthouseapp.com/";
        $this->username = $username;
        $this->password = $password;
    }

    public function API($methodURL)
    {
        $url = $this->baseURL . $methodURL;
        try
        {
            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_USERPWD, $this->username . ':' . $this->password);
            $result = curl_exec($curl);
            $info = curl_getinfo($curl, CURLINFO_HTTP_CODE);// TODO:autorization check
            curl_close($curl);

            return $result;

        }
        catch (Exception $e)
        {
            return $e->Message();
        }
    }

    public function getAllProjects()
    {
        $url = "projects.json";

        $jsonLighthouseProjects = $this->API($url);
        $jsonLighthouseProjects = json_decode ($jsonLighthouseProjects);

        $resultProjects = array();

        foreach($jsonLighthouseProjects->projects as $key => $value)
        {
            $resultProjects[$key] = array
            (
                "id" => $value->project->id,
                "name" => $value->project->name,
                "created" => $value->project->created_at,
                "updated" => $value->project->updated_at,
                "membership" => $value->project->membership_id,
                "archived" => $value->project->archived
            );
        }

        return $resultProjects;
    }

    public function getAllUsersForProject($projectID)
    {
        $url = sprintf("projects/$projectID/memberships.json");
        $jsonLighthouseUsers = json_decode($this->API($url));

        $resultUsersForProject = array();

        foreach($jsonLighthouseUsers->memberships as $index => $value)
        {
            $user = $value->membership->user;
            $resultUsersForProject[$index] = array
            (
                "id" => $user->id,
                "name" => $user->name
            );
        }

        return $resultUsersForProject;
    }

    public function getAllTicketsForProject($projectID)
    {
        $page = 1;
        $url = sprintf("projects/%d/tickets.json?page=%d", $projectID, $page);
        $jsonLighthouseTickets = json_decode($this->API($url));

        $ticketsForProject = array();

        while($jsonLighthouseTickets->tickets != null)
        {
            foreach($jsonLighthouseTickets->tickets as $index => $value)
            {
                $url = sprintf("projects/%d/tickets/%d.json", $projectID,  $value->ticket->number);

                $jsonLighthouseTicket = json_decode($this->API($url));
                $ticket = $jsonLighthouseTicket->ticket;

                $versions = $this->getVersionsForTicket($ticket);

                $attachments = $this->getAttachmentsForTicket($ticket);

                $ticketsForProject[$index+($page-1)*30] = array
                (
                    "assignedUserName" => $ticket->assigned_user_name,
                    "creationDate" => $ticket->created_at,
                    "number" => $ticket->number,
                    "priority" => $ticket->priority,
                    "projectID" => $ticket->project_id,
                    "state" => $ticket->state,
                    "tag" => $ticket->tag,
                    "title" => $ticket->title,
                    "version" => $ticket->version,
                    "userName" => $ticket->user_name,
                    "creatorName" => $ticket->creator_name,
                    "url" => $ticket->url,
                    "milestone" => $ticket->milestone_title,
                    "originalBody" => $ticket->original_body,
                    "latestBody" => $ticket->latest_body,
                    "versions" => $versions,
                    "attachments" => $attachments
                );
            }

            $page++;
            $url = sprintf("projects/%d/tickets.json?page=%d", $projectID, $page);
            $jsonLighthouseTickets = json_decode($this->API($url));
        }

        return $ticketsForProject;
    }

    public function getVersionsForTicket($ticket)
    {
        $commentsForTicket = array();

        foreach($ticket->versions as $index => $version)
        {
            $versionNumber = $version->version;

            if($versionNumber > 1)
            {
                $commentsForTicket[$versionNumber] = array("user" => $version->user_name);// TODO: change to user ID if necessary

                if($version->number != "")
                    $commentsForTicket[$versionNumber]["number"] = $version->version;

                if($version->body != "")
                    $commentsForTicket[$versionNumber]["body"] = $version->body;
                            
                foreach($version->diffable_attributes as $attribute => $value)
                {
                    if($attribute == "assigned_user")
                        $commentsForTicket[$versionNumber][$attribute] = array("old" => $value, "new" => $version->assigned_user_name);// TODO: change to user ID if necessary
                    else
                        $commentsForTicket[$versionNumber][$attribute] = array("old" => $value, "new" => $version->$attribute);// TODO: check for other special attributes
                }
            }
        }

        return $commentsForTicket;
    }

    public function getAttachmentsForTicket($ticket)
    {
        $attachments = array();

        foreach($ticket->attachments as $index => $value)
        {
            if($value->image != null)
            {
                $attachments[$index]["url"] = $value->image->url;
                $attachments[$index]["fileName"] = $value->image->filename;
            }
            else
            {
                $attachments[$index]["url"] = $value->attachment->url;
                $attachments[$index]["fileName"] = $value->attachment->filename;
            }
        }

        return $attachments;
    }

    public function downloadAttachment($url, $fileName)
    {
        $directory = 'Attachments';
        if (!file_exists($directory))
            mkdir($directory, 0777);

        $path = "{$directory}/" . $fileName;

        $file = fopen($path, 'w');
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true );
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($curl, CURLOPT_FILE, $file);
        $data = curl_exec($curl);
        $info = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        fclose($file);

        return  $path;
    }
}
?>