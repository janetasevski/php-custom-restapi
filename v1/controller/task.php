<?php
require_once('db.php');
require_once('../model/Response.php');
require_once('../model/Task.php');

try {
    $writeDB = DB::connectWriteDB();
    $readDB = DB::connectReadDB();
} catch (PDOException $ex) {
    error_log("Connection error - ".$ex, 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database connection error");
    $response->send();
    exit;
}

// Begin auth script
if (!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1) {
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    $response->addMessage("Access token is missing from the header");
    $response->send();
    exit;
}

$accesstoken = $_SERVER['HTTP_AUTHORIZATION'];

try {
    $query = $writeDB->prepare(
        "SELECT userid, accesstokenexpiry, useractive, loginattempts
        FROM tblsessions, tblusers
        WHERE tblsessions.userid = tblusers.id AND tblsessions.accesstoken = :accesstoken"
    );
    $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
    $query->execute();
    
    $rowCount = $query->rowCount();
    if ($rowCount === 0) {
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("Invalid Access Token");
        $response->send();
        exit;
    }
    
    $row = $query->fetch(PDO::FETCH_ASSOC);
    
    $returned_userid = $row['userid'];
    $returned_accesstokenexpiry = $row['accesstokenexpiry'];
    $returned_useractive = $row['useractive'];
    $returned_loginattempts = $row['loginattempts'];
    
    if ($returned_useractive !== 'Y') {
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("User account is not active");
        $response->send();
        exit;
    }
    
    if ($returned_loginattempts >= 3) {
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("User account is currently locked out");
        $response->send();
        exit;
    }
    
    if (strtotime($returned_accesstokenexpiry) < time()) {
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("Access Token has expired - please log in again");
        $response->send();
        exit;
    }
} 
catch (PDOException $ex) {
    error_log("DB error - ".$ex, 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Failed to check login details".$ex);
    $response->send();
    exit();
}
// end auth script
if (array_key_exists("taskid", $_GET)) {
    $taskid = $_GET['taskid'];
    if ($taskid == '' || !is_numeric($taskid)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Task id can not be blank or must be numeric");
        $response->send();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $query = $readDB->prepare("SELECT id, title, description, completed, DATE_FORMAT(deadline, '%d/%m/%Y %H:%i') AS deadline FROM tbltasks WHERE id = :taskid");
            $query->bindParam('taskid', $taskid, PDO::PARAM_INT);
            $query->execute();

            $rowcount = $query->rowCount();
            if ($rowcount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Task not found");
                $response->send();
                exit;
            }

            $tasksArray = array();
            while($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
                $tasksArray[] = $task->returnTaskAsArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowcount;
            $returnData['tasks'] = $tasksArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($returnData);
            $response->send();
            exit;
        } catch (TaskException $ex) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($ex);
            $response->send();
            exit;
        } catch (PDOException $ex) {
            error_log("Connection error - ".$ex, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get task");
            $response->send();
            exit;
        } 
    } 
    elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        try {
            $query = $writeDB->prepare('DELETE FROM tbltasks WHERE id = :taskid');
            $query->bindParam('taskid', $taskid, PDO::PARAM_INT);
            $query->execute();

            $rowcount = $query->rowCount();
            if ($rowcount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Task not found");
                $response->send();
                exit;
            }

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage("Task deleted");
            $response->send();
            exit;
        } catch (PDOException $ex) {
            error_log("Connection error - ".$ex, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to delete task");
            $response->send();
            exit;
        }
    } 
    elseif ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
        try {
            if (!isset($_SERVER['CONTENT_TYPE']) || $_SERVER['CONTENT_TYPE'] !== 'application/json') {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Content type header is not set to JSON");
                $response->send();
                exit;
            }

            $rawPOSTData = file_get_contents('php://input');
            if (!$jsonData = json_decode($rawPOSTData)) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Request body is not valid JSON");
                $response->send();
                exit;
            }

            $titleUpdated = false;
            $descriptionUpdated = false;
            $deadlineUpdated = false;
            $completedUpdated = false;
            $queryFields = "";
            if (isset($jsonData->title)) {
                $titleUpdated = true;
                $queryFields .= "title = :title, ";
            }
            if (isset($jsonData->description)) {
                $descriptionUpdated = true;
                $queryFields .= "description = :description, ";
            }
            if (isset($jsonData->deadline)) {
                $deadlineUpdated = true;
                $queryFields .= "deadline = STR_TO_DATE(:deadline, '%d/%m/%Y %H:%i'), ";
            }
            if (isset($jsonData->completed)) {
                $completedUpdated = true;
                $queryFields .= "completed = :completed, ";
            }
            // Remove last comma
            $queryFields = rtrim($queryFields, ", ");

            if ($titleUpdated == false && $descriptionUpdated == false && $deadlineUpdated == false && $completedUpdated = false) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("No task fields provided");
                $response->send();
                exit;
            }

            $query = $writeDB->prepare(
                "SELECT id, title, description, DATE_FORMAT(deadline, '%d/%m/%Y %H:%i') AS deadline, completed 
                FROM tbltasks 
                WHERE id = :taskid");
            $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();
            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("No task found to update");
                $response->send();
                exit;
            }

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
            }

            $queryString = "UPDATE tbltasks SET $queryFields WHERE id = :taskid";
            $query = $writeDB->prepare($queryString);

            if ($titleUpdated === true) {
                $task->setTitle($jsonData->title);
                $upTitle = $task->getTitle();
                $query->bindParam(":title", $upTitle, PDO::PARAM_STR);
            }
            if ($descriptionUpdated === true) {
                $task->setDescription($jsonData->description);
                $upDescription = $task->getDescription();
                $query->bindParam(":description", $upDescription, PDO::PARAM_STR);
            }
            if ($deadlineUpdated === true) {
                $task->setDeadLine($jsonData->deadline);
                $upDeadline = $task->getDeadline();
                $query->bindParam(":deadline", $upDeadline, PDO::PARAM_STR);
            }
            if ($completedUpdated === true) {
                $task->setCompleted($jsonData->completed);
                $upCompleted = $task->getCompleted();
                $query->bindParam(":completed", $upCompleted, PDO::PARAM_STR);
            }
            $query->bindParam(":taskid", $taskid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();
            if ($rowCount !== 1) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Problem updating task");
                $response->send();
                exit;
            }

            $query = $writeDB->prepare(
                "SELECT id, title, description, DATE_FORMAT(deadline, '%d/%m/%Y %H:%i') AS deadline, completed 
                FROM tbltasks 
                WHERE id = :taskid");
            $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
            $query->execute();
            
            $rowCount = $query->rowCount();
            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("No task found after update");
                $response->send();
                exit;
            }

            $tasksArray = array();
            while($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
                $tasksArray[] = $task->returnTaskAsArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $tasksArray;

            $response = new Response();
            $response->setHttpStatusCode(201);
            $response->setSuccess(true);
            $response->addMessage("Task updated");
            $response->setData($returnData);
            $response->send();
            exit;
        } 
        catch (TaskException $ex) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
        } 
        catch (PDOException $ex) {
            error_log("Connection error - ".$ex, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to update tasks");
            $response->send();
            exit();
        }

    } 
    else {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed.");
        $response->send();
        exit();
    }
} 
elseif (array_key_exists("completed", $_GET)) {
    
    $completed = $_GET['completed'];
    if($completed !== 'Y' && $completed !== 'N') {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Completed filter must be Y or N");
        $response->send();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $query = $readDB->prepare(
                "SELECT id, title, description, DATE_FORMAT(deadline, '%d/%m/%Y %H:%i') AS deadline, completed 
                FROM tbltasks 
                WHERE completed = :completed");
            $query->bindParam('completed', $completed, PDO::PARAM_STR);
            $query->execute();

            $rowCount = $query->rowCount();
            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Task not found");
                $response->send();
                exit;
            }
            $taskArray = array();
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
                $tasksArray[] = $task->returnTaskAsArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $tasksArray;
            
            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($returnData);
            $response->send();
            exit;
        } catch (TaskException $ex) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit;
        } catch (PDOException $ex) {
            error_log("Connection error - ".$ex, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get tasks");
            $response->send();
            exit;
        }
    } else {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed.");
        $response->send();
        exit();
    }
} 
elseif (array_key_exists("page", $_GET)) {
    $page = $_GET['page'];
    if ($page == '' || !is_numeric($page)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Page is not correct");
        $response->send();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $limitPerPage = 20;

        try {
            $query = $readDB->prepare("SELECT count(id) AS totalNoOfTasks FROM tbltasks");
            $query->execute();
            $row = $query->fetch(PDO::FETCH_ASSOC);
            $tasksCount = intval($row['totalNoOfTasks']);
            $numOfpages =  ceil($tasksCount / $limitPerPage);
            if($numOfpages == 0) {
                $numOfpages = 1;
            }
            if ($page > $numOfpages || $page == 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Page not exist");
                $response->send();
                exit;
            }

            $offset = ($page == 1 ? 0 : ($limitPerPage*($page-1)));

            $query = $readDB->prepare(
                "SELECT id, title, description, DATE_FORMAT(deadline, '%d/%m/%Y %H:%i') AS deadline, completed 
                FROM tbltasks 
                LIMIT :pglimit
                OFFSET :offset");
            $query->bindParam('pglimit', $limitPerPage, PDO::PARAM_INT);
            $query->bindParam('offset', $offset, PDO::PARAM_INT);
            $query->execute();
            
            $rowCount = $query->rowCount();
            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Task not found");
                $response->send();
                exit;
            }

            $taskArray = array();
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
                $tasksArray[] = $task->returnTaskAsArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['total_rows'] = $tasksCount;
            $returnData['total_pages'] = $numOfpages;
            $returnData['has_next_page'] = $page < $numOfpages ? true : false;
            $returnData['has_previous_page'] = $page > 1 ? true : false;
            $returnData['tasks'] = $tasksArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($returnData);
            $response->send();
            exit;
        } catch (TaskException $ex) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit;
        } catch (PDOException $ex) {
            error_log("Connection error - ".$ex, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get tasks".$ex);
            $response->send();
            exit;
        }
    } else {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed.");
        $response->send();
        exit();
    }
} 
elseif (empty($_GET)) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $query = $readDB->prepare(
                "SELECT id, title, description, DATE_FORMAT(deadline, '%d/%m/%Y %H:%i') AS deadline, completed 
                FROM tbltasks 
                ORDER BY id ASC");
            $query->execute();

            $rowCount = $query->rowCount();
            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Task not found");
                $response->send();
                exit;
            }

            $taskArray = array();
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
                $tasksArray[] = $task->returnTaskAsArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $tasksArray;
            
            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($returnData);
            $response->send();
            exit;
        } 
        catch (TaskException $ex) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
        } 
        catch (PDOException $ex) {
            error_log("Connection error - ".$ex, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get tasks");
            $response->send();
            exit();
        }
    }
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (!isset($_SERVER['CONTENT_TYPE']) || $_SERVER['CONTENT_TYPE'] !== 'application/json') {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Content type header is not set to JSON");
                $response->send();
                exit;
            }

            // Get body
            $rawPOSTData = file_get_contents('php://input');
            if (!$jsonData = json_decode($rawPOSTData)) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Request body is not valid JSON");
                $response->send();
                exit;
            }

            // Validationg
            if (!isset($jsonData->title) || !isset($jsonData->completed)) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                !isset($jsonData->title) ? $response->addMessage("Title field is mandatory and must be provided") : false;
                !isset($jsonData->completed) ? $response->addMessage("Completed field is mandatory and must be provided") : false;
                $response->send();
                exit;
            }
            $newTask = new Task(null, $jsonData->title, (isset($jsonData->description) ? $jsonData->description : null), (isset($jsonData->deadline) ? $jsonData->deadline : null), $jsonData->completed);
            
            $title = $newTask->getTitle();
            $description = $newTask->getDescription();
            $deadline = $newTask->getDeadline();
            $completed = $newTask->getCompleted();

            
            $query = $writeDB->prepare(
                "INSERT INTO tbltasks (title, description, deadline, completed, userid) 
                VALUES (:title, :description, STR_TO_DATE(:deadline, '%d/%m/%Y %H:%i'), :completed)");
                
            $query->bindParam(":title", $title, PDO::PARAM_STR);
            $query->bindParam(":description", $description, PDO::PARAM_STR);
            $query->bindParam(":deadline", $deadline, PDO::PARAM_STR);
            $query->bindParam(":completed", $completed, PDO::PARAM_STR);
            $query->bindParam(":userid", $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();
            if ($rowCount == 0) {
                $response = new Response();
                $response->setHttpStatusCode(500);
                $response->setSuccess(false);
                $response->addMessage("Failed to create task");
                $response->send();
                exit;
            }
            
            $lastTaskId = $writeDB->lastInsertId();
            $query = $writeDB->prepare("SELECT id, title, description, completed, DATE_FORMAT(deadline, '%d/%m/%Y %H:%i') AS deadline FROM tbltasks WHERE id = :taskid");
            $query->bindParam('taskid', $lastTaskId, PDO::PARAM_INT);
            $query->execute();
            
            $rowcount = $query->rowCount();
            if ($rowcount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(500);
                $response->setSuccess(false);
                $response->addMessage("Failed to retrive task from creation");
                $response->send();
                exit;
            }

            $tasksArray = array();
            while($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
                $tasksArray[] = $task->returnTaskAsArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowcount;
            $returnData['tasks'] = $tasksArray;

            $response = new Response();
            $response->setHttpStatusCode(201);
            $response->setSuccess(true);
            $response->addMessage("Task created");
            $response->setData($returnData);
            $response->send();
            exit;
        } 
        catch (TaskException $ex) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
        } 
        catch (PDOException $ex) {
            error_log("Connection error - ".$ex, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to add tasks");
            $response->send();
            exit();
        }
    }
    else {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed.");
        $response->send();
        exit();
    }
} 
else {
    $response = new Response();
    $response->setHttpStatusCode(404);
    $response->setSuccess(false);
    $response->addMessage("Endpoint does not found");
    $response->send();
    exit;
}