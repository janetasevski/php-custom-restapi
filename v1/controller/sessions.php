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

if (array_key_exists("sessionid", $_GET)) {
    $sessionid = $_GET['sessionid'];
    if ($sessionid == '' || !is_numeric($sessionid)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Session id can not be blank or must be numeric");
        $response->send();
        exit;
    }

    if (!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Access token is missing from the header");
        $response->send();
        exit;
    }

    $accesstoken = $_SERVER['HTTP_AUTHORIZATION'];

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        try {
            $query = $writeDB->prepare("DELETE FROM tblsessions WHERE id = :sessionid and accesstoken = :accesstoken");
            $query->bindParam(':sessionid', $sessionid, PDO::PARAM_INT);
            $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
            $query->execute();

            $rowCount = $query->rowCount();
            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Session or accesstoken not found for logout");
                $response->send();
                exit;
            }

            $returnData = array();
            $returnData['session_id'] = intval($sessionid);

            $response = new Response();
            $response->setHttpStatusCode(201);
            $response->setSuccess(true);
            $response->addMessage("Logged out");
            $response->setData($returnData);
            $response->send();
            exit;

        } catch (PDOException $ex) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("There was issue removing session");
            $response->send();
            exit();
        }
    }
    elseif ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
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

        if (!isset($jsonData->refresh_token) || strlen($jsonData->refresh_token) < 1) {
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            !isset($jsonData->refresh_token) ? $response->addMessage("Refresh token not supplied") : false;
            strlen($jsonData->refresh_token) < 1 ? $response->addMessage("Refresh token cannot be blank") : false;
            $response->send();
            exit;
        }

        try {
            $refreshtoken = $jsonData->refresh_token;

            $query = $writeDB->prepare(
                "SELECT tblsessions.id as sessionid, tblsessions.userid as userid, accesstoken, refreshtoken, useractive, loginattempts, accesstokenexpiry, refreshtokenexpiry 
                FROM tblsessions, tblusers
                WHERE tblusers.id = tblsessions.userid AND tblsessions.id = :sessionid AND tblsessions.accesstoken = :accesstoken AND tblsessions.refreshtoken = :refreshtoken"
            );
            $query->bindParam(':sessionid', $sessionid, PDO::PARAM_INT);
            $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
            $query->bindParam(':refreshtoken', $refreshtoken, PDO::PARAM_STR);
            $query->execute();

            $rowCount = $query->rowCount();
            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("Access token or Refresh token is incorrect for session id");
                $response->send();
                exit;
            }

            $row = $query->fetch(PDO::FETCH_ASSOC);

            $returned_sessionid = $row['sessionid'];
            $returned_userid = $row['userid'];
            $returned_accesstoken = $row['accesstoken'];
            $returned_refreshtoken = $row['refreshtoken'];
            $returned_useractive = $row['useractive'];
            $returned_loginattempts = $row['loginattempts'];
            $returned_accesstokenexpiry = $row['accesstokenexpiry'];
            $returned_refreshtokenexpiry = $row['refreshtokenexpiry'];

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

            if (strtotime($returned_refreshtokenexpiry) < time()) {
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("Refresh token has expired - please log in again");
                $response->send();
                exit;
            }

            // Generate new access and refresh token
            $accesstoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24).time()));
            $refreshtoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24).time()));
            $access_token_expiry_secconds = 1200;
            $refresh_token_expiry_secconds = 1209600; 

            $query = $writeDB->prepare(
                "UPDATE tblsessions SET 
                accesstoken = :accesstoken, accesstokenexpiry = DATE_ADD(NOW(), INTERVAL :accesstokenexpiry SECOND), refreshtoken = :refreshtoken, refreshtokenexpiry = DATE_ADD(NOW(), INTERVAL :refreshtokenexpiry SECOND)
                WHERE id = :sessionid AND accesstoken = :returnedaccesstoken AND refreshtoken = :returnedrefreshtoken"
            );
            $query->bindParam(':sessionid', $returned_sessionid, PDO::PARAM_INT);
            $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
            $query->bindParam(':accesstokenexpiry', $access_token_expiry_secconds, PDO::PARAM_INT);
            $query->bindParam(':refreshtoken', $refreshtoken, PDO::PARAM_STR);
            $query->bindParam(':refreshtokenexpiry', $refresh_token_expiry_secconds, PDO::PARAM_INT);
            $query->bindParam(':returnedaccesstoken', $returned_accesstoken, PDO::PARAM_STR);
            $query->bindParam(':returnedrefreshtoken', $returned_refreshtoken, PDO::PARAM_STR);
            $query->execute();
            
            $rowCount = $query->rowCount();
            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("Access token could not be refreshed - please log in again");
                $response->send();
                exit;
            }

            $returnData = array();
            $returnData['session_id'] = $returned_sessionid;
            $returnData['access_token'] = $accesstoken;
            $returnData['access_token_expires_in'] = $access_token_expiry_secconds;
            $returnData['refresh_token'] = $refreshtoken;
            $returnData['refresh_token_expires_in'] = $refresh_token_expiry_secconds;

            $response = new Response();
            $response->setHttpStatusCode(201);
            $response->setSuccess(true);
            $response->addMessage("Token refreshed");
            $response->setData($returnData);
            $response->send();
            exit;
            
        }
        catch (PDOException $ex) {
            error_log("DB error - ".$ex, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to refresh access token".$ex);
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
elseif (empty($_GET)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed.");
        $response->send();
        exit();
    }

    sleep(1);

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

    if (!isset($jsonData->username) || !isset($jsonData->password)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        !isset($jsonData->username) ? $response->addMessage("Username field is mandatory and must be provided") : false;
        !isset($jsonData->password) ? $response->addMessage("Password field is mandatory and must be provided") : false;
        $response->send();
        exit;
    }

    if (strlen($jsonData->username) < 1 || strlen($jsonData->username) > 255 || strlen($jsonData->password) < 1 || strlen($jsonData->password) > 255) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        strlen($jsonData->username) < 1 ? $response->addMessage("Username cannot be empty") : false;
        strlen($jsonData->username) > 255 ? $response->addMessage("Username cannot be grater than 255 characters") : false;
        strlen($jsonData->password) < 1 ? $response->addMessage("Password cannot be empty") : false;
        strlen($jsonData->password) > 255 ? $response->addMessage("Password cannot be grater than 255 characters") : false;
        $response->send();
        exit;
    }

    try {
        $username = trim($jsonData->username);
        $password = $jsonData->password;

        $query = $writeDB->prepare("SELECT * FROM tblusers WHERE username = :username");
        $query->bindParam(':username', $username, PDO::PARAM_STR);
        $query->execute();

        $rowCount = $query->rowCount();
        if ($rowCount === 0) {
            $response = new Response();
            $response->setHttpStatusCode(401);
            $response->setSuccess(false);
            $response->addMessage("Username or password is incorrect");
            $response->send();
            exit;
        }

        $row = $query->fetch(PDO::FETCH_ASSOC);

        $returned_id = $row['id'];
        $returned_fullname = $row['fullname'];
        $returned_username = $row['username'];
        $returned_password = $row['password'];
        $returned_useractive = $row['useractive'];
        $returned_loginattempts = $row['loginattempts'];

        if ($returned_useractive !== 'Y') {
            $response = new Response();
            $response->setHttpStatusCode(401);
            $response->setSuccess(false);
            $response->addMessage("User account not active");
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

        if (!password_verify($password, $returned_password)) {
            $query = $writeDB->prepare("UPDATE tblusers SET loginattempts = loginattempts+1 WHERE id = :id");
            $query->bindParam(':id', $returned_id, PDO::PARAM_INT);
            $query->execute();

            $response = new Response();
            $response->setHttpStatusCode(401);
            $response->setSuccess(false);
            $response->addMessage("Username or password is incorrect");
            $response->send();
            exit;
        }

        $accesstoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24).time()));
        $refreshtoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24).time()));
        $access_token_expiry_secconds = 1200;
        $refresh_token_expiry_secconds = 1209600;

    } catch (PDOException $ex) {
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage("There was issue logging in");
        $response->send();
        exit();
    }

    // Transaction. For that we use new try catch
    try {
        $writeDB->beginTransaction();
        $query = $writeDB->prepare("UPDATE tblusers SET loginattempts = 0 WHERE id = :id");
        $query->bindParam(':id', $returned_id, PDO::PARAM_INT);
        $query->execute();

        $query = $writeDB->prepare(
            "INSERT INTO tblsessions (userid, accesstoken, accesstokenexpiry, refreshtoken, refreshtokenexpiry) 
            VALUES (:userid, :accesstoken, DATE_ADD(NOW(), INTERVAL :accesstokenexpiry SECOND), :refreshtoken, DATE_ADD(NOW(), INTERVAL :refreshtokenexpiry SECOND))"
        );
        $query->bindParam(':userid', $returned_id, PDO::PARAM_INT);
        $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
        $query->bindParam(':accesstokenexpiry', $access_token_expiry_secconds, PDO::PARAM_INT);
        $query->bindParam(':refreshtoken', $refreshtoken, PDO::PARAM_STR);
        $query->bindParam(':refreshtokenexpiry', $refresh_token_expiry_secconds, PDO::PARAM_INT);
        $query->execute();

        $lastSessionId = $writeDB->lastInsertId();

        $writeDB->commit();

        $returnData = array();
        $returnData['session_id'] = intval($lastSessionId);
        $returnData['access_token'] = $accesstoken;
        $returnData['access_token_expires_in'] = $access_token_expiry_secconds;
        $returnData['refresh_token'] = $refreshtoken;
        $returnData['refresh_token_expires_in'] = $refresh_token_expiry_secconds;

        $response = new Response();
        $response->setHttpStatusCode(201);
        $response->setSuccess(true);
        $response->setData($returnData);
        $response->send();
        exit;

    } catch (PDOException $ex) {
        // Roll back is something fail
        $writeDB->rollBack();
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage("There was issue logging in".$ex);
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