<?php
/**
 * @file
 * @version 0.1
 * @copyright 2021 wesen <wesen-ac@web.de>
 * @author wesen
 */

$targetPlayerName = 'wesen';
$targetPlayerIp = null;
$absoluteDemoFilePath = realpath('./20210725_2254_51.38.185.3_XX_Olympic_TimeRun_GEMA_4min_CTF.dmo');
//$absoluteDemoFilePath = realpath('./20210726_1742_51.38.185.3_RooftopGema_4min_CTF.dmo');
$absoluteDemoFilePath = realpath('./20210730_2322_51.38.185.3_SE-GEMA-24_9min_CTF.dmo');

function findDemoMetaData(string $_absoluteDemoFilePath)
{
  $demoFileName = basename($_absoluteDemoFilePath);
  $findDemoMetaDataCommand = sprintf(
    'docker run
       --rm
       -v "%s:/demos/%s"
     wesen1/assaultcube-demo-processor finddemometadata
       --demo /demos/%s',
    $_absoluteDemoFilePath,
    $demoFileName,
    $demoFileName,
  );
  $findDemoMetaDataCommand = preg_replace('/\s/', ' ', $findDemoMetaDataCommand);

  exec($findDemoMetaDataCommand, $outputLines, $returnValue);
  if ($returnValue !== 0)
  {
    return null;
  }

  $output = join(PHP_EOL, $outputLines);
  $json = json_decode($output);
  if ($json === null)
  {
    //var_dump(json_last_error_msg());
    return null;
  }

  return $json;
}

function findBestScore(string $_absoluteDemoFilePath, string $_targetPlayerName = null, string $_targetPlayerIp = null)
{
  $bestScoreInfo = null;

  $demoFileName = basename($_absoluteDemoFilePath);
  $findBestScoreTimesCommand = sprintf(
    'docker run
       --rm
       -v "%s:/demos/%s"
     wesen1/assaultcube-demo-processor findbestscoretimes
       --demo /demos/%s',
    $_absoluteDemoFilePath,
    $demoFileName,
    $demoFileName,
  );
  $findBestScoreTimesCommand = preg_replace('/\s/', ' ', $findBestScoreTimesCommand);

  exec($findBestScoreTimesCommand, $outputLines, $returnValue);
  if ($returnValue !== 0)
  {
    return null;
  }

  $output = join(PHP_EOL, $outputLines);
  $json = json_decode($output);
  if ($json === null)
  {
    //var_dump(json_last_error_msg());
    return null;
  }

  $scoreInfos = $json->bestScoresPerPlayer ?? null;
  if (is_array($scoreInfos))
  {
    foreach ($scoreInfos as $scoreInfo)
    {
      $nameMatchesTargetPlayerName = ($_targetPlayerName === null || isset($scoreInfo->name) && $scoreInfo->name == $_targetPlayerName);
      $ipMatchesTargetPlayerIp = ($_targetPlayerIp === null || isset($scoreInfo->ip) && $scoreInfo->ip == $_targetPlayerIp);

      if ($nameMatchesTargetPlayerName &&
          $ipMatchesTargetPlayerIp &&
          isset($scoreInfo->scoreTimeInMilliseconds))
      {
        if ($bestScoreInfo === null ||
            $bestScoreInfo->scoreTimeInMilliseconds > $scoreInfo->scoreTimeInMilliseconds)
        {
          $bestScoreInfo = $scoreInfo;
        }
      }
    }
  }

  return $bestScoreInfo;
}

function normalizeDemo(string $_absoluteDemoFilePath, stdClass $_demoMetaData)
{
  $outputDirectoryPath = __DIR__ . '/normalized-demos';
  if (!is_dir($outputDirectoryPath))
  {
    mkdir($outputDirectoryPath, 0777, true);
  }

  $demoFileName = basename($_absoluteDemoFilePath);

  $timestamp = new DateTime("now", new DateTimeZone("UTC"));
  $timestamp->setTimestamp($_demoMetaData->timestamp);
  $outputFileName = sprintf(
      "%s_%s_%s_%d.dmo",
      $timestamp->format("Y_m_d_H_i_s"),
      $_demoMetaData->gameMode,
      $_demoMetaData->mapName,
      $_demoMetaData->mapRevision
  );

  $messageTypesToRemove = [
    'SV_SERVMSG', // Server notification
    'SV_TEXT', // Chat
    'SV_VOICECOM' // Voicecom sound
  ];

  $normalizeDemoCommand = sprintf(
    'docker run
       --rm
       -v "%s:/demos/%s"
       -v "%s:/normalized-demos"
     wesen1/assaultcube-demo-processor removemessagetypes
       --demo /demos/%s
       --output /normalized-demos/%s
       --types %s',
    $_absoluteDemoFilePath,
    $demoFileName,
    $outputDirectoryPath,
    $demoFileName,
    $outputFileName,
    join(" ", $messageTypesToRemove)
  );
  $normalizeDemoCommand = preg_replace('/\s/', ' ', $normalizeDemoCommand);

  exec($normalizeDemoCommand, $outputLines, $returnValue);

  if ($returnValue === 0)
  {
    return $outputDirectoryPath . '/' . $outputFileName;
  }
  else return null;
}


// Archive

function fetchItemMetaData(string $_itemIdentifier): stdClass
{
    $fetchMetaDataCommand = sprintf('
      docker run
        --rm
      wesen1/internetarchive metadata %s',
                                    $_itemIdentifier
    );
    $fetchMetaDataCommand = preg_replace('/\s/', ' ', $fetchMetaDataCommand);

    exec($fetchMetaDataCommand, $outputLines, $returnValue);
    if ($returnValue === 0)
    {
        $metaDataJsonString = join(PHP_EOL, $outputLines);
        return json_decode($metaDataJsonString);
    }
    else throw new Exception("Fetching meta data failed");
}

function findFileInfosWithSameName(stdClass $_metaData, string $_fileName): array
{
    $fileInfos = $_metaData->files ?? [];

    $fileInfosWithSameName = [];
    foreach ($fileInfos as $fileInfo)
    {
        $fileName = $fileInfo->name ?? "";
        if (substr($fileName, 0, strlen($_fileName)) === $_fileName)
        {
            $fileInfosWithSameName[] = $fileInfo;
            break;
        }
    }

    return $fileInfosWithSameName;
}

function findFileInfosWithHashSums(array $_fileInfos, string $_sha1, string $_md5): array
{
    $fileInfosWithMatchingHashSums = [];
    foreach ($_fileInfos as $fileInfo)
    {
        $hashSumFound = false;
        if (property_exists($fileInfo, "sha1"))
        {
            $hashSumFound = true;
            if ($_sha1 !== $fileInfo->sha1) continue;
        }

        if (property_exists($fileInfo, "md5"))
        {
            $hashSumFound = true;
            if ($_md5 !== $fileInfo->md5) continue;
        }

        if ($hashSumFound)
        {
            $fileInfosWithMatchingHashSums[] = $fileInfo;
        }
    }

    return $fileInfosWithMatchingHashSums;
}

function findFileInfoWithContent(string $_itemIdentifier, array $_fileInfos, string $_content)
{
    $fileInfoWithSameContent = null;

    $downloadDirectory = sys_get_temp_dir() . "/downloads";
    if (!is_dir($downloadDirectory))
    {
        mkdir($downloadDirectory, 0777, true);
    }

    foreach ($_fileInfos as $fileInfo)
    {
        $downloadFileCommand = sprintf('
          docker run
            --rm
            -v "%s:/downloads"
          wesen1/internetarchive download %s %s
            --destdir=/downloads/',
          $downloadDirectory,
          $_itemIdentifier,
          $fileInfo->name
        );
        $downloadFileCommand = preg_replace('/\s/', ' ', $downloadFileCommand);

        exec($downloadFileCommand, $outputLines, $returnValue);
        if ($returnValue === 0)
        {
            $downloadFilePath = sprintf(
                '%s/%s/%s',
                $downloadDirectory,
                $_itemIdentifier,
                $fileInfo->name
            );

            if (file_exists($downloadFilePath))
            {
                $downloadedFileContents = file_get_contents($downloadFilePath);
                if ($downloadedFileContents === $_content)
                {
                    $fileInfoWithSameContent = $fileInfo;
                    break;
                }
            }
            else
            {
                //echo "Warning: Failed to read downloaded file" . PHP_EOL;
            }
        }
        else
        {
            //echo "Warning: Failed to download file" . PHP_EOL;
        }
    }

    //rmdir($downloadDirectory);

    return $fileInfoWithSameContent;
}


function archiveDemo(string $_mapName, string $_demoFilePath): string
{
    $itemIdentifier = sprintf("assaultcube_speedrun_demos_" . $_mapName);

    $itemBaseUrl = sprintf('https://archive.org/download/%s', $itemIdentifier);
    $demoFileName = basename($_demoFilePath, ".dmo");
    $uploadDemoFileName = sprintf('%s.dmo', $demoFileName);
    $uploadedDemoFileName = null;

    $metaDataJson = fetchItemMetaData($itemIdentifier);
    if (!empty(get_object_vars($metaDataJson)))
    { // No empty response, this is an already existing item

        $existingFileInfosWithSameName = findFileInfosWithSameName($metaDataJson, $demoFileName);

        if (!empty($existingFileInfosWithSameName))
        { // The file seems to already exist
            $demoFileContents = file_get_contents($_demoFilePath);
            $sha1 = sha1($demoFileContents);
            $md5 = md5($demoFileContents);

            $existingFileInfosWithSameHashSums = findFileInfosWithHashSums($existingFileInfosWithSameName, $sha1, $md5);
            if (!empty($existingFileInfosWithSameHashSums))
            { // Found files with similar hash sums, download them and check the file contents
                $fileInfoWithSameContent = findFileInfoWithContent(
                    $itemIdentifier,
                    $existingFileInfosWithSameHashSums,
                    $demoFileContents
                );


                if ($fileInfoWithSameContent)
                { // Duplicate
                    $uploadedDemoFileName = $fileInfoWithSameContent->name;
                }
                else
                { // Need to upload but change name

                    $existingDemoFileNames = array_map(function($_fileInfo){
                        return $_fileInfo->name;
                    }, $existingFileInfosWithSameName);

                    $counter = 1;
                    do
                    {
                        $uploadDemoFileName = sprintf('%s_%d.dmo', $demoFileName, $counter);
                        $counter++;
                    }
                    while (in_array($uploadDemoFileName, $existingDemoFileNames));
                }
            }
        }
    }

    if (!$uploadedDemoFileName)
    {
        if (basename($_demoFilePath) === $uploadDemoFileName)
        {
            $uploadFilePath = $_demoFilePath;
        }
        else
        {
            $uploadsDirectory = sys_get_temp_dir() . '/uploads';
            if (!is_dir($uploadsDirectory)) mkdir($uploadsDirectory, 0777, true);

            $uploadFilePath = $uploadsDirectory . '/' . $uploadDemoFileName;
            copy($_demoFilePath, $uploadFilePath);
        }

        $configDirectory = __DIR__ . "/config/internetarchive";
        $uploadFileCommand = sprintf('
          docker run
            --rm
            -v "%s:/root/.config/internetarchive/"
            -v "%s:/uploads/%s"
          wesen1/internetarchive upload %s /uploads/%s',
          $configDirectory,
          $uploadFilePath,
          $uploadDemoFileName,
          $itemIdentifier,
          $uploadDemoFileName
        );
        $uploadFileCommand = preg_replace('/\s/', ' ', $uploadFileCommand);

        exec($uploadFileCommand, $outputLines, $returnValue);
        if ($returnValue === 0)
        {
            $uploadedDemoFileName = $uploadDemoFileName;
        }
        else throw new Exception('Failed to upload file');
    }

    return $itemBaseUrl . '/' . $uploadedDemoFileName;
}





printf('Fetching demo meta data ... ');
$demoMetaData = findDemoMetaData($absoluteDemoFilePath);
printf('OK' . PHP_EOL);
printf('Meta data: %s' . PHP_EOL . PHP_EOL, json_encode($demoMetaData));

printf('Normalizing demo ... ');
$normalizedDemoFilePath = normalizeDemo($absoluteDemoFilePath, $demoMetaData);
printf('OK' . PHP_EOL);
printf('Normalized demo file path: %s' . PHP_EOL . PHP_EOL, $normalizedDemoFilePath);

printf('Archiving demo file ... ');
$archivedDemoFileUrl = archiveDemo($demoMetaData->mapName, $normalizedDemoFilePath);
printf('OK' . PHP_EOL);
printf('Archived demo file URL: %s' . PHP_EOL . PHP_EOL, $archivedDemoFileUrl);


printf('Searching for best score of player in demo ... ');
$bestScoreInfo = findBestScore($absoluteDemoFilePath, $targetPlayerName, $targetPlayerIp);
printf('OK' . PHP_EOL);
printf('Best score: %s' . PHP_EOL . PHP_EOL, json_encode($bestScoreInfo));


// Render normalized demo best score

$demoFileName = basename($normalizedDemoFilePath);
$demoName = basename($normalizedDemoFilePath, '.dmo');
$outputDirectoryPath = __DIR__ . '/rendered-demos';
$videoName = 'demo';
$packagesDirectoryPath = __DIR__ . '/packages'; // TODO

$renderBestScoreCommand = sprintf(
  'docker run
     --rm
     -v "%s:/home/user/.assaultcube_v1.2/demos/%s"
     -v "%s:/home/user/.assaultcube_v1.2/packages"
     -v "%s:/rendered-demos"
     --env DEMO_NAME="%s"
     --env OUTPUT_FILE="/rendered-demos/%s.mp4"
     --env FOLLOW_CLIENT_NUMBER=%d
     --env START_TIMESTAMP=%d
     --env END_TIMESTAMP=%d
   wesen1/assaultcube-demo-videofier',
  $normalizedDemoFilePath,
  $demoFileName,
  $packagesDirectoryPath,
  $outputDirectoryPath,
  $demoName,
  $videoName,
  $bestScoreInfo->clientNumber ?? 0,
  ($bestScoreInfo->startTimestamp ?? 3000) - 3000,
  ($bestScoreInfo->endTimestamp ?? 7000) + 3000,
);

$renderBestScoreCommand = preg_replace('/\s/', ' ', $renderBestScoreCommand);
var_dump($renderBestScoreCommand);
passthru($renderBestScoreCommand);
//exec($renderBestScoreCommand, $outputLines, $returnValue);
