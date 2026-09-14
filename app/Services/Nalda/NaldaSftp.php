<?php

namespace App\Services\Nalda;

use App\Models\NaldaConnection;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\PhpseclibV3\UnableToAuthenticate;
use League\Flysystem\PhpseclibV3\UnableToConnectToSftpHost;
use League\Flysystem\PhpseclibV3\UnableToEstablishAuthenticityOfHost;
use Throwable;

class NaldaSftp
{
    /**
     * Check that the connection's credentials can log in and read the server.
     *
     * @throws NaldaUploadException
     */
    public function check(NaldaConnection $connection): void
    {
        $this->attempt(function () use ($connection) {
            // Listing is lazy, so reading the first entry is what actually
            // connects and logs in.
            foreach ($this->filesystem($connection)->listContents('/', false) as $entry) {
                break;
            }
        });
    }

    /**
     * Write the stream to the server under the given file name.
     *
     * @param  resource  $stream
     *
     * @throws NaldaUploadException
     */
    public function upload(NaldaConnection $connection, string $fileName, $stream): void
    {
        $this->attempt(fn () => $this->filesystem($connection)->writeStream($fileName, $stream));
    }

    /**
     * Run an SFTP operation, turning its failures into ones the user can read.
     *
     * @throws NaldaUploadException
     */
    private function attempt(callable $operation): void
    {
        try {
            $operation();
        } catch (UnableToAuthenticate $exception) {
            throw NaldaUploadException::authentication($exception);
        } catch (UnableToConnectToSftpHost|UnableToEstablishAuthenticityOfHost $exception) {
            throw NaldaUploadException::unreachable($exception);
        } catch (FilesystemException $exception) {
            throw NaldaUploadException::writeFailed($exception);
        } catch (Throwable $exception) {
            // phpseclib raises plain runtime errors for dropped connections.
            report($exception);

            throw NaldaUploadException::unreachable($exception);
        }
    }

    /**
     * Build a filesystem rooted at the seller's SFTP home.
     */
    private function filesystem(NaldaConnection $connection): Filesystem
    {
        $provider = new SftpConnectionProvider(
            host: $connection->sftpHost(),
            username: (string) $connection->sftp_username,
            password: (string) $connection->sftp_password,
            port: $connection->sftpPort(),
            timeout: (int) config('services.nalda.sftp_timeout'),
            maxTries: 2,
        );

        return new Filesystem(new SftpAdapter($provider, '/', disconnectOnDestruct: true));
    }
}
