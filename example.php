<?php

// Import phpNextcloud class into the global namespace
// These must be at the top of your script, not inside a function
use LaswitchTech\phpNextcloud\phpNextcloud;

// Load Composer's autoloader
require 'vendor/autoload.php';

// Initialize Database
$phpNextcloud = new phpNextcloud();

// Set log level
$phpNextcloud->config("level",5);

// Test
$Share = $phpNextcloud->File->getShareProperties('kPAznDpN7AT8aFJ');
$Files = $phpNextcloud->File->getFiles(trim($Share['path'],'/'));
echo PHP_EOL . "Properties of Share: " . PHP_EOL . json_encode($Share, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
echo PHP_EOL . "List of Files: " . PHP_EOL . json_encode($Files, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
foreach($Files as $File){
    if(isset($File['d:getcontentlength'])){
        $Properties = $phpNextcloud->File->getFileProperties($File['path']);
        echo PHP_EOL . "Properties of File: " . PHP_EOL . json_encode($Properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        echo PHP_EOL . "iframe: " . PHP_EOL . json_encode(str_replace('index.php','index.php/apps/onlyoffice',$Share['url']).'?fileId='.$Properties['oc:fileid'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        echo PHP_EOL . "download: " . PHP_EOL . json_encode($Share['url'].'/download?path='.urlencode('/').'&files='.$Properties['filename'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
exit;

// Create Sample File
$fileName = "sample.txt";
$fileBlob = "data:text/plain;base64," . base64_encode("Hello World!");
echo PHP_EOL . "File(" . $fileName . "): " . PHP_EOL;
var_dump($fileBlob);

// If File/Directory Exist
$exist = $phpNextcloud->File->exist('uploads');
// echo PHP_EOL . "Does it Exist: " . PHP_EOL . json_encode($exist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);exit;
$exist = $phpNextcloud->File->exist('upload');
// echo PHP_EOL . "Does it Exist: " . PHP_EOL . json_encode($exist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);exit;

// Upload File
$upload = $phpNextcloud->File->upload($fileName, $fileBlob);
// echo PHP_EOL . "Upload File: " . PHP_EOL . json_encode($upload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);exit;

// Properties of File
if(isset($upload,$upload['path'])){
    $getFileProperties = $phpNextcloud->File->getFileProperties($upload['path']);
    // echo PHP_EOL . "Properties of File: " . PHP_EOL . json_encode($getFileProperties, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);exit;
}

// Content of File
if(isset($upload,$upload['path'])){
    $getFileContentByPath = $phpNextcloud->File->getFileContentByPath($upload['path']);
    // echo PHP_EOL . "Content of File: " . PHP_EOL . json_encode($getFileContentByPath, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);exit;
    $getFileContentByPathEncoded = $phpNextcloud->File->getFileContentByPath($upload['path'],true);
    // echo PHP_EOL . "Content of File(encoded): " . PHP_EOL . json_encode($getFileContentByPathEncoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);exit;
}

// Shares of File
if(isset($upload,$upload['path'])){
    $getShares = $phpNextcloud->File->getShares($upload['path']);
    // echo PHP_EOL . "Shares of File: " . PHP_EOL . json_encode($getShares, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);exit;
}

// Share File
if(isset($upload,$upload['path'])){
    $shareOptions = [
        'permissions' => $phpNextcloud->File->permission('READ'), // READ, UPDATE, CREATE, DELETE, SHARE, ALL
        'hideDownload' => true // Hide the download option
        // 'password' => '!QAZxsw2321654987',
        // 'expireDate' => '2023-12-31',
        // 'note' => 'This is a note for the share',
    ];
    $share = $phpNextcloud->File->share($upload['path'], $shareOptions);
    // echo PHP_EOL . "Share File: " . PHP_EOL . json_encode($share, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);exit;
}

// Properties of Share
if(isset($share,$share['token'])){
    $getShareProperties = $phpNextcloud->File->getShareProperties($share['token']);
    // echo PHP_EOL . "Properties of Share: " . PHP_EOL . json_encode($getShareProperties, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);exit;
}

// Content of Share
if(isset($share,$share['token'])){
    $getFileContentByShareToken = $phpNextcloud->File->getFileContentByShareToken($share['token']);
    // echo PHP_EOL . "Content of Share: " . PHP_EOL . json_encode($getFileContentByShareToken, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);exit;
    $getFileContentByShareTokenEncoded = $phpNextcloud->File->getFileContentByShareToken($share['token'],true);
    // echo PHP_EOL . "Content of Share(encoded): " . PHP_EOL . json_encode($getFileContentByShareTokenEncoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);exit;
}

// // Create a Directory
$makeDirectory = $phpNextcloud->File->makeDirectory('uploads/client');
// echo PHP_EOL . "Create a Directory: " . PHP_EOL . json_encode($makeDirectory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);exit;

// // List of Files in Directory
$getFiles = $phpNextcloud->File->getFiles('uploads');
echo PHP_EOL . "List of Files: " . PHP_EOL . json_encode($getFiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);exit;

// Properties of a Directory
$getDirectoryProperties = $phpNextcloud->File->getFileProperties('uploads');
// echo PHP_EOL . "Properties of Directory: " . PHP_EOL . json_encode($getDirectoryProperties, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);exit;

// Check if path is a Directory
$isDirectory = $phpNextcloud->File->isDirectory('uploads');
// echo PHP_EOL . "Is it a Directory: " . PHP_EOL . json_encode($isDirectory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);exit;

// Copy
if(isset($getFileProperties,$getFileProperties['path'],$getFileProperties['filename'])){
    $copy = $phpNextcloud->File->copy($getFileProperties['path'],'uploads/client/'.$getFileProperties['filename']);
    // echo PHP_EOL . "Copy: " . PHP_EOL . json_encode($copy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);exit;
}

// Move
if(isset($getFileProperties,$getFileProperties['path'],$getFileProperties['filename'])){
    $move = $phpNextcloud->File->move('uploads/client/'.$getFileProperties['filename'],'uploads/client/'.md5($getFileProperties['filename']));
    // echo PHP_EOL . "Move: " . PHP_EOL . json_encode($move, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);exit;
}

// Unshare
if(isset($share,$share['token'])){
    $unshare = $phpNextcloud->File->unshare($share['token']);
    // echo PHP_EOL . "Unshare: " . PHP_EOL . json_encode($unshare, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);exit;
}

// Delete
if(isset($upload,$upload['path'])){
    $deleteFile = $phpNextcloud->File->delete($upload['path']);
    // echo PHP_EOL . "Delete File: " . PHP_EOL . json_encode($deleteFile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);exit;
    $deleteDirectory = $phpNextcloud->File->delete('uploads/client');
    // echo PHP_EOL . "Delete a Directory: " . PHP_EOL . json_encode($deleteDirectory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);exit;
}

echo PHP_EOL . "Done!" . PHP_EOL;