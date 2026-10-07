<?php

declare(strict_types=1);

namespace Php\Pie\Building;

use Composer\IO\IOInterface;
use LogicException;
use Php\Pie\Building\ExtensionBinaryNotFound;
use Php\Pie\Downloading\DownloadedPackage;
use Php\Pie\Downloading\DownloadUrlMethod;
use Php\Pie\File\BinaryFile;
use Php\Pie\Platform\Architecture;
use Php\Pie\Platform\TargetPlatform;
use Php\Pie\Platform\ThreadSafetyMode;
use Php\Pie\Platform\WindowsExtensionAssetName;
use Php\Pie\Util\Process;
use ZipArchive;

use function sprintf;

/** @internal This is not public API for PIE, so should not be depended upon unless you accept the risk of BC breaks */
final class WindowsBuild implements Build
{
    /** {@inheritDoc} */
    public function __invoke(
        DownloadedPackage $downloadedPackage,
        TargetPlatform $targetPlatform,
        array $configureOptions,
        IOInterface $io,
    ): BinaryFile {
        $selectedDownloadMethod = DownloadUrlMethod::fromDownloadedPackage($downloadedPackage);
        switch ($selectedDownloadMethod) {
            case DownloadUrlMethod::PrePackagedBinary:
                return $this->prePackagedBinary($downloadedPackage, $targetPlatform, $io);

            case DownloadUrlMethod::ComposerDefaultDownload:
            case DownloadUrlMethod::PrePackagedSourceDownload:
                return $this->buildFromSource($downloadedPackage, $targetPlatform, $configureOptions, $io);

            default:
                throw new LogicException('Unsupported download method: ' . $selectedDownloadMethod->value);
        }
    }

    private function prePackagedBinary(
        DownloadedPackage $downloadedPackage,
        TargetPlatform $targetPlatform,
        IOInterface $io,
    ): BinaryFile {
        $prebuiltDll = WindowsExtensionAssetName::determineDllName($targetPlatform, $downloadedPackage);

        $io->write(
            sprintf(
                '<info>Nothing to build on Windows, prebuilt DLL found:</info> %s',
                $prebuiltDll,
            ),
            verbosity: IOInterface::VERBOSE,
        );

        return BinaryFile::fromFileWithSha256Checksum($prebuiltDll);
    }

    /** @param list<non-empty-string> $configureOptions */
    private function buildFromSource(
        DownloadedPackage $downloadedPackage,
        TargetPlatform $targetPlatform,
        array $configureOptions,
        IOInterface $io,
    ): BinaryFile {
        // Alternatively to the “in-tree” build described above, you can do a “phpize” build, what is mostly useful if you don't need to build PHP from source, but rather use a pre-built PHP binary package.
        // 1 Download and unpack the development package which corresponds to your pre-built PHP version and variant from https://www.php.net/downloads.php?os=windows&osvariant=windows-downloads
        ["phpize" => $phpizeCommand, "starterScript" => $starterScriptCommand] = $this->downloadDevelopmentPackages($targetPlatform, $io);
        
        // [2 Download and unpack the source of the PECL extension] already done (DownloadedPackage)

        // 3 Invoke the starter script to automatically setup the environment for the desired build config, e.g. c:\php-sdk\phpsdk-vs16-x64.bat
        // [4 Add the development package folder and the PHP folder to the PATH] we use the absolute path to the starter script and phpize command
        // [5 Enter the source folder of the PECL extension] we use $downloadedPackage->extractedSourcePath as the working directory
        // [6 Run phpize] done by build.bat
        /*
        $phpizePath = $targetPlatform->phpizePath ?? PhpizePath::guessFrom($targetPlatform->phpBinaryPath);
        /* 
         * Call a cleanup first; most of the time, we expect to be changing a
         * version (e.g. upgrade, downgrade), in which case the source is
         * already clean anyway; however, sometimes we want to rebuild the
         * current ext, so this will perform a clean first
        * /
        $this->cleanup($phpizePath, $downloadedPackage, $io, $outputCallback);

        $this->phpize(
            $phpizePath,
            $downloadedPackage,
            $io,
            $outputCallback,
        );
        */

        // [7 Run configure --help to see the list of configuration options] done by build.bat
        //     the most important option is the one which enables the extension to be built (e.g. --enable-apcu)
        //     another important options is --with-prefix which expects the PHP folder to be passed
        //     if the extension depends on C libraries, you need to download these and put them either in the --with-php-build folder, or use the --with-extra-includes and --with-extra-libs options; suitable pre-built libraries can be found on https://downloads.php.net/~windows/php-sdk/deps/ and https://downloads.php.net/~windows/pecl/deps/
        //     there may be further interesting configuration options, e.g. those which allows to configure details of the extension to be built
        // 8 Run configure with the desired options
        // TODO: figure out --enable- flags dynamically
        /* 
            $io->write('<info>phpize complete</info>.');

            $configureOptions = $this->withDetectedLibdirOption($configureOptions, $targetPlatform);

            $phpConfigPath = $targetPlatform->phpBinaryPath->phpConfigPath();
            if ($phpConfigPath !== null) {
                $configureOptions[] = '--with-php-config=' . $phpConfigPath;
            }

            $this->configure($downloadedPackage, $configureOptions, $io, $outputCallback);

            $optionsOutput = count($configureOptions) ? ' with options: ' . implode(' ', $configureOptions) : '.';
        */

        // [9 Run nmake] done by build.bat
        /*
            $io->write('<info>Configure complete</info>' . $optionsOutput);

            try {
                $this->make($targetPlatform, $downloadedPackage, $io, $outputCallback);
            } catch (ProcessFailedException $p) {
                throw ProcessFailedWithLimitedOutput::fromProcessFailedException($p);
            }
        */

        // 10 After successful compilation, the build artifacts are located in the release folder
        /* 
            $expectedSoFile = $downloadedPackage->extractedSourcePath . '/modules/' . $downloadedPackage->package->extensionName()->name() . '.so';

            if (! file_exists($expectedSoFile)) {
                throw ExtensionBinaryNotFound::fromExpectedBinary($expectedSoFile);
            }

            $io->write(sprintf(
                '<info>Build complete:</info> %s',
                $expectedSoFile,
            ));

            return BinaryFile::fromFileWithSha256Checksum($expectedSoFile);
        */

        // 11 If the extension has a PHPT test suite, run nmake test

        // php-sdk-binary-tools-php-sdk-2.8.4/phpsdk-starter.bat -c vc17 -a x64 -t build4.bat
        $compiler = strtolower( $targetPlatform->windowsCompiler->name );
        $architecture = $targetPlatform->architecture === Architecture::x86_64 ? "x64" : "x86";
        $task = realpath("toolchain/build.bat");

        if( !file_exists($task) ){
            throw new \RuntimeException("File does not exist!");
        }

        // Try to find enable/with to enable the extension
        $configureOptions = $this->tryEnableExtension($downloadedPackage, $configureOptions, $io);
        
        $configureArgs = implode(" ", $configureOptions);       

        $outputCallback = Process::outputCallbackForVerbosity($io, IOInterface::DEBUG);
        $result = Process::run(
            [
                $starterScriptCommand,
                "-c", $compiler,
                "-a", $architecture,
                "-t", $task,
                "--task-args", "$phpizeCommand $configureArgs",
            ],
            $downloadedPackage->extractedSourcePath,
            timeout: 600, // Allow at least 10 minutes for the compilation
            outputCallback: $outputCallback
        );
        
        $io->write("RESULT: ". $result);


        //  `Release`: Release NTS build
        // `Release_TS`: Release ZTS build
        // `Debug`: Debug NTS build
        // `Debug_TS`: Debug ZTS build

        $buildDirectory = sprintf("$architecture\Release%s", $targetPlatform->threadSafety === ThreadSafetyMode::ThreadSafe ? "_TS" : "");

        $expectedDllFile = $downloadedPackage->extractedSourcePath . "\\$buildDirectory\\php_" . $downloadedPackage->package->extensionName()->name() . '.dll';

        if (! file_exists($expectedDllFile)) {
            throw ExtensionBinaryNotFound::fromExpectedBinary($expectedDllFile);
        }

        $io->write(sprintf(
            '<info>Build complete:</info> %s',
            $expectedDllFile,
        ));

        return BinaryFile::fromFileWithSha256Checksum($expectedDllFile);
    }

    private function downloadDevelopmentPackages(TargetPlatform $targetPlatform, IOInterface $io){       
        $sdkFilenameWithoutSuffix = sprintf("php-sdk-%s", "2.8.4");
        $sdkFilename = "$sdkFilenameWithoutSuffix.zip";
        //https://downloads.php.net/~windows/releases/archives/php-devel-pack-8.5.11-nts-Win32-vs17-x86.zip
        $sdkDownloadUrl = "https://github.com/php/php-sdk-binary-tools/archive/refs/tags/$sdkFilename";

        if ( file_exists("$sdkFilename") ) {
            $io->write("SDK package already exists: $sdkFilename");
        } else {
            $this->downloadFile($sdkDownloadUrl, $sdkFilename, $io);
        }
        
        $za = new ZipArchive();
        $za->open($sdkFilename);
        $firstEntry = $za->getNameIndex(0);
        $io->write("SDK entry " . $firstEntry ?: "<unknown>");

        $toolchainDirectory = "toolchain";
        $sdkPath = "$toolchainDirectory/$firstEntry";
        if( file_exists($sdkPath)) {
            $io->write("SDK already exists");
        } else {
            $io->write("Unpacking SDK to $sdkPath");
            $za->extractTo($toolchainDirectory);
        }
       
        $developmentPackageFilenameWithoutSuffix = sprintf(
            "php-devel-pack-%s%s-Win32-%s-%s",
            $targetPlatform->phpBinaryPath->version(),
            $targetPlatform->threadSafety->asShort() === "ts" ? "": "-nts",
            strtolower( $targetPlatform->windowsCompiler->name ),
            $targetPlatform->architecture === Architecture::x86_64 ? "x64" : "x86"
        );
        $developmentPackageFilename = "$developmentPackageFilenameWithoutSuffix.zip";
        $developmentPackageUrl = "https://downloads.php.net/~windows/releases/archives/$developmentPackageFilename";

        if ( file_exists("$developmentPackageFilename") ) {
            $io->write("Development package already exists: $developmentPackageFilename");
        } else {
            $this->downloadFile($developmentPackageUrl, $developmentPackageFilename, $io);
        }
        
        $za = new ZipArchive();
        $za->open($developmentPackageFilename);
        $firstEntry = $za->getNameIndex(0);
        $io->write("Devpack entry " . $firstEntry ?: "<unknown>");

        $devpackPath = "$toolchainDirectory/$firstEntry";
        
        if( file_exists($devpackPath)) {
            $io->write("Devpack already exists");
        } else {
            $io->write("Unpacking devpack to $devpackPath");
            $za->extractTo($toolchainDirectory);
        }
            
        $starterScriptFilename = sprintf("phpsdk-%s-%s.bat",             
            strtolower( $targetPlatform->windowsCompiler->name ),
            $targetPlatform->architecture === Architecture::x86_64 ? "x64" : "x86"
        );

        $starterScript = realpath("$sdkPath/$starterScriptFilename");
        $io->write("$sdkPath/$starterScriptFilename - realpath: '".$starterScript."'");
        $phpize = realpath("$devpackPath/phpize.bat");
        $io->write("$devpackPath/phpize.bat - realpath: '".$phpize."'");

        return ["phpize" => $phpize, "starterScript" => $starterScript];
    }

    private function downloadFile(string $url, string $outputFilename, IOInterface $io, $verbose=false){
        $curlHandle = null;
        $fileHandle = null;
        try {
            $io->write("Downloading file from $url to $outputFilename");
            $fileHandle = fopen($outputFilename, "wb");

            $curlHandle = curl_init($url);
            if( !$curlHandle ){
                throw new \RuntimeException("Failed to open curl session");
            }
            
            curl_setopt($curlHandle, CURLOPT_FILE, $fileHandle);
            curl_setopt($curlHandle, CURLOPT_VERBOSE, $verbose);
            curl_setopt($curlHandle, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($curlHandle, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
            curl_exec($curlHandle);
            $statusCode = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
            if (curl_errno($curlHandle)) {
                throw new \RuntimeException("Failed to download file with error " . curl_error($curlHandle));
            }
            else if ($statusCode !== 200){
                throw new \RuntimeException("Failed to download file with status code: $statusCode");
                
            }

            $io->write("Download successful");
        }
        finally {
            if (!is_null( $curlHandle) )  {
                if (PHP_VERSION_ID >= 80000) {
                    unset($curlHandle);
                } else {
                    curl_close($curlHandle);
                }
            }
            
            if( !is_null($fileHandle)){
                fflush($fileHandle);
                fclose($fileHandle);
            }   
        }
    }

    /** @param list<non-empty-string> $configureOptions */
    private function tryEnableExtension(DownloadedPackage $downloadedPackage, array $configureOptions, IOInterface $io){
        $enableExtensionOptionName = "enable-".$downloadedPackage->package->extensionName()->name();
        $enableExtensionOption = "--$enableExtensionOptionName";
        $enableExtensionOptionValue = "$enableExtensionOption=shared";

        $withExtensionOptionName = "with-".$downloadedPackage->package->extensionName()->name();
        $withExtensionOption = "--$withExtensionOptionName";
        $withExtensionOptionValue = "$withExtensionOption=shared";

        $foundExtensionOption = false;
        foreach ( $downloadedPackage->package->configureOptions() as $availableOption){
            // TODO: make sure this check can handle the optional assigned value as well
            if ($availableOption->name === $enableExtensionOptionName){
                if (!in_array($enableExtensionOption, $configureOptions)){
                    $io->write("Found config option $enableExtensionOption to enable the extension: adding $enableExtensionOptionValue to configuration.");
                    array_push( $configureOptions, $enableExtensionOptionValue );
                } else {
                    $io->write("Found config option $enableExtensionOption to enable the extension: already set.");
                }
                $foundExtensionOption = true;
                break;
            }
            else if ($availableOption->name === $withExtensionOptionName){
                // TODO: make sure this check can handle the optional assigned value as well
                if (!in_array($withExtensionOption, $configureOptions)){
                    $io->write("Found config option $withExtensionOption to enable the extension: adding $withExtensionOptionValue to configuration.");
                    array_push( $configureOptions, $withExtensionOptionValue );
                }
                else {
                    $io->write("Found config option $withExtensionOption to enable the extension: already set.");
                }
                
                $foundExtensionOption = true;
                break;
            }
        }
    
        if (!$foundExtensionOption){
            throw new \RuntimeException("Did not find any configuration option to enable the extension ". $downloadedPackage->package->extensionName()->name());
        }

        return $configureOptions;
    }
}
