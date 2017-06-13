<?php
namespace Redoc;
define("DIR_SEP", "/");

require_once(__DIR__ . "/Icon.php");
require_once(__DIR__ . "/ZipException.php");
require_once(__DIR__ . "/PPngUncrush.php");

use CFPropertyList\CFPropertyList;
use Exception;

class IpaParser {
    const ZIP = "7z";
    const MV = "mv";
    const RM = "rm";
    const PYTHON = "python";
    const IPIN_SCRIPT = "ipin.py";

    const PLIST = "Info.plist";
    const PROVISION = "embedded.mobileprovision";
    const OUTPUT_DIR = "ipa-info";
    const OUTPUT_TEMP = "ipa-tmp";
    const APP_ICON = "app-icon.png";
    const VERSION_NAME = "versionName";
    const VERSION_CODE = "versionCode";
    const PACKAGE_NAME = "packageName";
    const MIN_SDK = "minSDK";
    const MIN_SDK_LEVEL = "minSDKLevel";
    const TARGET_SDK = "targetSDK";
    const DATE = "date";
    const ICON_PATH = "iconPath";
    const RAW = "raw";
    const HREF_PLIST = "";

    const APP_NAME = "appName";
    const BUNDLE_INDENTIFIER = "bundleIndentifier";


    private $ipaFilePath;
    private $outputDir;
    private $indexRoot;
    private $options;

    /** @var  Icon */
    private $icon;
    /** @var  CFPropertyList */
    private $plist;
    private $provision;
    private $extractFolder;
    private $parsed;

    public function __construct($ipaFilePath, $outputDir = "", $indexRoot = "", $options = []) {
        //check ipa file exist
        if (!file_exists($ipaFilePath)) {
            $errMsg = sprintf("ipa file not exist\nfile path: %s", $ipaFilePath);
            throw new Exception(nl2br($errMsg));
        }
        $this->ipaFilePath = $ipaFilePath;

        //check output is directory
        $this->outputDir = self::OUTPUT_DIR;
        if (!empty($outputDir)) {
            $this->outputDir = $this->removeSpace($outputDir);
        }

        $this->indexRoot = $indexRoot;

        if (!is_dir($this->outputDir) || !file_exists($this->outputDir)) {
            mkdir($this->outputDir, 0744, true);
        }

        $this->options = $options;

        $this->parsed = false;

        $md5Input = $this->ipaFilePath + filemtime($this->ipaFilePath);

        $this->extractFolder = $this->outputDir . DIR_SEP . md5($md5Input);

        $this->parse();
    }


    private function parse() {
        $this->checkParse();

        $iconPath = $this->extractFolder . DIR_SEP . self::APP_ICON;
        $plistPath = $this->extractFolder . DIR_SEP . self::PLIST;
        $provisionPath = $this->extractFolder . DIR_SEP . self::PROVISION;

        if ($this->parsed) {
            $shoudReparse = false;

            if (!file_exists($this->extractFolder . DIR_SEP . self::PLIST)) {
                $msg = "Parsed file ipa! But plist no longer exist";
                trigger_error($msg, E_USER_WARNING);
                $shoudReparse = true;
            }

            //clean extracted folder for re-parse
            if ($shoudReparse) {
                $this->parsed = false;
                $rmCommand = sprintf("%s -rf %s", self::RM, $this->extractFolder);
                $this->cmd(self::RM, $rmCommand, Exception::class);
            }
        }

        if (!$this->parsed) {
            //unzip ipa, take out Info.plist and *.png
            $imgPattern = "*.png";
            $plistPattern = "Info.plist";       //*.plist
            $provisionPattern = "*.mobileprovision";       //*.mobileprovision
            $zipCommand = sprintf("%s x %s -aoa -o%s %s %s %s -r", self::ZIP, $this->ipaFilePath, $this->extractFolder, $imgPattern, $plistPattern, $provisionPattern);
            $this->cmd(self::ZIP, $zipCommand, ZipException::class);

            //make sure it is extracted successfully
            if (!is_dir($this->extractFolder) || !file_exists($this->extractFolder)) {
                trigger_error("failed to extract IPA to folder " . $this->extractFolder, E_USER_ERROR);
                return;
            }

            //move *.png to $this->extractFolder
            //becauses move cannot move to itself, move to tmp folder, then move back
            if (!is_dir(self::OUTPUT_TEMP) && !file_exists(self::OUTPUT_TEMP))
                mkdir(self::OUTPUT_TEMP, 0744, true);

            $dir = scandir($this->extractFolder . DIR_SEP . "Payload");
            if (empty($dir)) {
                trigger_error("failed to scan folder " . $this->extractFolder, E_USER_ERROR);
                return;
            }

            $appFolder = $dir[2];

            //rename XYZ.app to a standard name, also to avoid SPACE & special characters
            $oldFolder = $appFolder;
            $appFolder = "extracted.app";
            $fullAppFolder = $this->extractFolder . DIR_SEP . "Payload" . DIR_SEP . $appFolder;
            $renameCommand = sprintf("mv '%s' %s", $this->extractFolder . DIR_SEP . "Payload" . DIR_SEP . $oldFolder, $fullAppFolder);
            $this->cmd(self::MV, $renameCommand, Exception::class);

            //move Info.plist outside
            rename($fullAppFolder . DIR_SEP . self::PLIST, $plistPath);

            $this->moveFiles($fullAppFolder, $imgPattern);

            //move embedded.mobileprovision outside
            rename($this->extractFolder . DIR_SEP . "Payload" . DIR_SEP . $appFolder . DIR_SEP . self::PROVISION, $provisionPath);

            //PNG files
            $imgFiles = glob($fullAppFolder . DIR_SEP . $imgPattern);
            $imgFiles = array_merge($imgFiles, glob($this->extractFolder . DIR_SEP . $imgPattern));
            asort($imgFiles);

            //Decode largest icon first
            $i = count($imgFiles) - 1;
            $pngUncrushed = new PPngUncrush();
            while ($i >= 0) {
                $pngFile = realpath($imgFiles[$i]);
                $ipinCommand = sprintf("%s %s %s", self::PYTHON, __DIR__ . DIR_SEP . self::IPIN_SCRIPT, $pngFile);
                $successDecode = $this->cmd(self::MV, $ipinCommand, Exception::class);
                if (!$successDecode) {
                    $i--;
                    continue;
                }

                //Move the normalized PNG file
                rename($pngFile, $iconPath);
                break;
            }
        }

        //store plist
        $this->plist = new CFPropertyList($plistPath);

        //store icon
        if (file_exists($iconPath))
            $this->icon = new Icon($iconPath);



        if(file_exists($provisionPath)){

            $fileRaw = file_get_contents($provisionPath);

            $fileRaw = substr($fileRaw, strpos($fileRaw, '<?xml version="1.0" encoding="UTF-8"?>'));
            $fileRaw = substr($fileRaw, 0, strpos($fileRaw, '</plist>') + strlen('</plist>'));

            if(file_put_contents($provisionPath, $fileRaw)){

                $provision = new CFPropertyList($provisionPath);

                $this->provision = $provision;

            }
        }
    }

    /**
     * @param string $cmd
     * @param string $command
     * @param Exception $exceptionType
     * @return string
     */
    private function cmd($cmd, $command, $exceptionType) {
        exec($command, $out, $resultCode);
        if ($resultCode != 0) {
            $msg = sprintf("Error when execute cmd: %s", $command);
            //trigger_error($msg, E_USER_NOTICE);

            //foreach ($out as $line) 
            //    trigger_error($line, E_USER_NOTICE);
            return false;
        }
        return true;
    }

    public function getIcon() {
        return $this->icon;
    }

    public function getPlist() {
        return $this->plist;
    }

    public function getProvision() {
        return $this->provision;
    }

    public function getExtractFolder(){
        return $this->extractFolder;
    }

    public function getBasicInfo() {
        $info = [];
        $plistArray = $this->plist->toArray();
        $info[self::BUNDLE_INDENTIFIER] = $plistArray["CFBundleIdentifier"];
        $info[self::VERSION_NAME] = $plistArray["CFBundleShortVersionString"];
        $info[self::VERSION_CODE] = $plistArray["CFBundleVersion"];
        $info[self::MIN_SDK] = $plistArray["MinimumOSVersion"];
        $dateString = date("Y-m-d H:i:s", filemtime($this->ipaFilePath));
        $info[self::DATE] = $dateString;

        //App name
        if (isset($plistArray["CFBundleDisplayName"]))
            $info[self::APP_NAME] = $plistArray["CFBundleDisplayName"];
        else if (isset($plistArray["CFBundleName"]))
            $info[self::APP_NAME] = $plistArray["CFBundleName"];

        return $info;
    }

    /**
     * @param $imgPath
     * @param $imgPattern
     */
    private function moveFiles($imgPath, $imgPattern) {
        //MOVE FILE EASILY FAILED when something doesn't match it
        //but it doesn't have HUGE IMPACT on result
        //bypass exception, just exec
        $mvCommand = sprintf("%s %s %s", self::MV, $imgPath . DIR_SEP . $imgPattern, self::OUTPUT_TEMP);
        exec($mvCommand);

        $mvCommand = sprintf("%s %s %s", self::MV, self::OUTPUT_TEMP . DIR_SEP . $imgPattern, $this->extractFolder);
        exec($mvCommand);
    }

    private function removeSpace($name) {
        return preg_replace('/\s+/', '-', $name);
    }

    protected function checkParse() {
        if (is_dir($this->extractFolder) && file_exists($this->extractFolder)) {
            $this->parsed = true;
        }
    }
}