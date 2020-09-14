<?php
require_once('db.php');
require_once('../model/Response.php');

try {
    $writeDB = DB::connectWriteDB();
} catch (PDOException $ex) {
    error_log("Connection error - ".$ex, 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database connection error");
    $response->send();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response = new Response();
    $response->setHttpStatusCode(405);
    $response->setSuccess(false);
    $response->addMessage("Request method not allowed");
    $response->send();
    exit;
}

if (!isset($_SERVER['CONTENT_TYPE']) || $_SERVER['CONTENT_TYPE'] !== 'application/json') {
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    $response->addMessage("Content type header is not set to JSON");
    $response->send();
    exit;
}

$rawPOSTData = file_get_contents('php://input');
if (!$jsonData = json_decode($rawPOSTData)){
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    $response->addMessage("Request body is not valid JSON");
    $response->send();
    exit;
}

if (!isset($jsonData->fullname) || !isset($jsonData->username) || !isset($jsonData->password)) {
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    !isset($jsonData->fullname) ? $response->addMessage("Full name is mandatory and must be provided") : false;
    !isset($jsonData->username) ? $response->addMessage("Username is mandatory and must be provided") : false;
    !isset($jsonData->password) ? $response->addMessage("Password is mandatory and must be provided") : false;
    $response->send();
    exit;
}

if (strlen($jsonData->fullname) < 1 || strlen($jsonData->fullname) > 255 || strlen($jsonData->username) < 1 || strlen($jsonData->username) > 255 || strlen($jsonData->password) < 1 || strlen($jsonData->password) > 255) {
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    strlen($jsonData->fullname) < 1 ? $response->addMessage("Full name cannot be empty") : false;
    strlen($jsonData->fullname) > 255 ? $response->addMessage("Full name cannot be grater than 255 characters") : false;
    strlen($jsonData->username) < 1 ? $response->addMessage("Username cannot be empty") : false;
    strlen($jsonData->username) > 255 ? $response->addMessage("Username cannot be grater than 255 characters") : false;
    strlen($jsonData->password) < 1 ? $response->addMessage("Password cannot be empty") : false;
    strlen($jsonData->password) > 255 ? $response->addMessage("Password cannot be grater than 255 characters") : false;
    $response->send();
    exit;
}

$fullname = trim($jsonData->fullname);
$username = trim($jsonData->username);
$password = $jsonData->password;

try {
    $query = $writeDB->prepare("SELECT id FROM tblusers WHERE username = :username");
    $query->bindParam(':username', $username, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();
    if ($rowCount !== 0) {
        $response = new Response();
        $response->setHttpStatusCode(409);
        $response->setSuccess(false);
        $response->addMessage("Username already exist");
        $response->send();
        exit;
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $query = $writeDB->prepare("INSERT INTO tblusers (fullname, username, password) VALUES (:fullname, :username, :password)");
    $query->bindParam(':fullname', $fullname, PDO::PARAM_STR);
    $query->bindParam(':username', $username, PDO::PARAM_STR);
    $query->bindParam(':password', $hashed_password, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();
    if ($rowCount === 0) {
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage("There was a problem creating user account");
        $response->send();
        exit;
    }

    $lastUserID = $writeDB->lastInsertId();

    $returnDara = array();
    $returnDara['user_id'] = $lastUserID;
    $returnDara['fullname'] = $fullname;
    $returnDara['username'] = $username;

    $response = new Response();
    $response->setHttpStatusCode(201);
    $response->setSuccess(true);
    $response->addMessage("User created");
    $response->setData($returnDara);
    $response->send();
} 
catch (PDOException $ex) {
    error_log("DB query error - ".$ex, 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Failed to create user account");
    $response->send();
    exit();
}