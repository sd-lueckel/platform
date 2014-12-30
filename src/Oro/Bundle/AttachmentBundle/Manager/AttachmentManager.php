<?php

namespace Oro\Bundle\AttachmentBundle\Manager;

use Doctrine\ORM\EntityManager;

use Symfony\Component\Security\Core\Util\ClassUtils;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\Filesystem\Filesystem as SymfonyFileSystem;
use Symfony\Component\HttpFoundation\File\File as FileType;

use Knp\Bundle\GaufretteBundle\FilesystemMap;

use Gaufrette\Filesystem;
use Gaufrette\StreamMode;
use Gaufrette\Adapter\MetadataSupporter;
use Gaufrette\Stream\Local as LocalStream;

use Oro\Bundle\AttachmentBundle\Entity\File;
use Oro\Bundle\EntityConfigBundle\DependencyInjection\Utils\ServiceLink;

class AttachmentManager
{
    const READ_COUNT = 100000;
    const DEFAULT_IMAGE_WIDTH = 100;
    const DEFAULT_IMAGE_HEIGHT = 100;

    /** @var Filesystem */
    protected $filesystem;

    /** @var  Router */
    protected $router;

    /** @var  array */
    protected $fileIcons;

    /**
     * @var ServiceLink
     */
    protected $securityFacadeLink;

    /**
     * @param FilesystemMap $filesystemMap
     * @param Router        $router
     * @param ServiceLink   $securityFacadeLink
     * @param array         $fileIcons
     */
    public function __construct(
        FilesystemMap $filesystemMap,
        Router $router,
        ServiceLink $securityFacadeLink,
        $fileIcons
    ) {
        $this->filesystem         = $filesystemMap->get('attachments');
        $this->router             = $router;
        $this->fileIcons          = $fileIcons;
        $this->securityFacadeLink = $securityFacadeLink;
    }

    /**
     * Copy file by $fileUrl (local path or remote file), copy it to temp dir and return Attachment entity record
     *
     * @param string $fileUrl
     * @return File|null
     */
    public function prepareRemoteFile($fileUrl)
    {
        try {
            $fileName           = pathinfo($fileUrl)['basename'];
            $parametersPosition = strpos($fileName, '?');
            if ($parametersPosition) {
                $fileName = substr($fileName, 0, $parametersPosition);
            }
            $filesystem = new SymfonyFileSystem();
            $tmpDir = ini_get('upload_tmp_dir');
            if (!$tmpDir || !is_dir($tmpDir) || !is_writable($tmpDir)) {
                $tmpDir = sys_get_temp_dir();
            }
            $tmpFile    = realpath($tmpDir) . DIRECTORY_SEPARATOR . $fileName;
            $filesystem->copy($fileUrl, $tmpFile, true);
            $file       = new FileType($tmpFile);
            $attachment = new File();
            $attachment->setFile($file);
            $this->preUpload($attachment);

            return $attachment;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Update attachment entity before upload
     *
     * @param File $entity
     */
    public function preUpload(File $entity)
    {
        if ($entity->isEmptyFile()) {
            if ($this->filesystem->has($entity->getFilename())) {
                $this->filesystem->delete($entity->getFilename());
            }
            $entity->setFilename(null);
            $entity->setExtension(null);
            $entity->setOriginalFilename(null);
        }

        if ($entity->getFile() !== null && $entity->getFile()->isFile()) {
            $entity->setOwner($this->securityFacadeLink->getService()->getLoggedUser());
            $file = $entity->getFile();
            if ($entity->getFilename() !== null && $this->filesystem->has($entity->getFilename())) {
                $this->filesystem->delete($entity->getFilename());
            }
            $entity->setExtension($file->guessExtension());

            if ($file instanceof UploadedFile) {
                $entity->setOriginalFilename($file->getClientOriginalName());
                $entity->setMimeType($file->getClientMimeType());
                $entity->setFileSize($file->getClientSize());
            } else {
                $entity->setOriginalFilename($file->getFileName());
                $entity->setMimeType($file->getMimeType());
                $entity->setFileSize($file->getSize());
            }

            $entity->setFilename(uniqid() . '.' . $entity->getExtension());

            $fsAdapter = $this->filesystem->getAdapter();
            if ($fsAdapter instanceof MetadataSupporter) {
                $fsAdapter->setMetadata(
                    $entity->getFilename(),
                    ['contentType' => $entity->getMimeType()]
                );
            }
        }
    }

    /**
     * Upload attachment file
     *
     * @param File $entity
     */
    public function upload(File $entity)
    {
        if ($entity->getFile() !== null && $entity->getFile()->isFile()) {
            $file = $entity->getFile();
            $this->copyLocalFileToStorage($file->getPathname(), $entity->getFilename());
        }
    }

    /**
     * Copy file from local filesystem to attachment storage with new name
     *
     * @param string $localFilePath
     * @param string $destinationFileName
     */
    public function copyLocalFileToStorage($localFilePath, $destinationFileName)
    {
        $src = new LocalStream($localFilePath);
        $dst = $this->filesystem->createStream($destinationFileName);

        $src->open(new StreamMode('rb+'));
        $dst->open(new StreamMode('wb+'));

        while (!$src->eof()) {
            $dst->write($src->read(self::READ_COUNT));
        }
        $dst->close();
        $src->close();
    }

    /**
     * Get file content
     *
     * @param File $entity
     * @return string
     */
    public function getContent(File $entity)
    {
        return $this->filesystem->get($entity->getFilename())->getContent();
    }

    /**
     * Get attachment url
     *
     * @param object $parentEntity
     * @param string $fieldName
     * @param File   $entity
     * @param string $type
     * @param bool   $absolute
     * @return string
     */
    public function getFileUrl($parentEntity, $fieldName, File $entity, $type = 'get', $absolute = false)
    {
        return $this->getAttachment(
            ClassUtils::getRealClass($parentEntity),
            $parentEntity->getId(),
            $fieldName,
            $entity,
            $type,
            $absolute
        );
    }

    /**
     * Get human readable file size
     *
     * @param integer $bytes
     * @return string
     */
    public function getFileSize($bytes)
    {
        $sz = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        $key = (int)$factor;

        return isset($sz[$key]) ? sprintf("%.2f", $bytes / pow(1000, $factor)) . ' ' . $sz[$key] : $bytes;
    }

    /**
     * Get attachment url
     *
     * @param string $parentClass
     * @param int    $parentId
     * @param string $fieldName
     * @param File   $entity
     * @param string $type
     * @param bool   $absolute
     * @return string
     */
    public function getAttachment(
        $parentClass,
        $parentId,
        $fieldName,
        File $entity,
        $type = 'get',
        $absolute = false
    ) {
        $urlString = str_replace(
            '/',
            '_',
            base64_encode(
                implode(
                    '|',
                    [
                        $parentClass,
                        $fieldName,
                        $parentId,
                        $type,
                        $entity->getOriginalFilename()
                    ]
                )
            )
        );
        return $this->router->generate(
            'oro_attachment_file',
            [
                'codedString' => $urlString,
                'extension'   => $entity->getExtension()
            ],
            $absolute
        );
    }

    /**
     * Return url parameters from encoded string
     *
     * @param $urlString
     * @return array
     *   - parent class
     *   - field name
     *   - entity id
     *   - download type
     *   - original filename
     * @throws \LogicException
     */
    public function decodeAttachmentUrl($urlString)
    {
        if (!($decodedString = base64_decode(str_replace('_', '/', $urlString)))
            || count($result = explode('|', $decodedString)) < 5
        ) {
            throw new \LogicException('Input string is not correct attachment encoded parameters');
        }

        return $result;
    }

    /**
     * Get resized image url
     *
     * @param File $entity
     * @param int  $width
     * @param int  $height
     * @return string
     */
    public function getResizedImageUrl(
        File $entity,
        $width = self::DEFAULT_IMAGE_WIDTH,
        $height = self::DEFAULT_IMAGE_HEIGHT
    ) {
        return $this->router->generate(
            'oro_resize_attachment',
            [
                'width'    => $width,
                'height'   => $height,
                'id'       => $entity->getId(),
                'filename' => $entity->getOriginalFilename()
            ]
        );
    }

    /**
     * Get filetype icon
     *
     * @param File $entity
     * @return string
     */
    public function getAttachmentIconClass(File $entity)
    {
        if (isset($this->fileIcons[$entity->getExtension()])) {
            return $this->fileIcons[$entity->getExtension()];
        }

        return $this->fileIcons['default'];
    }

    /**
     * Get image attachment link with liip imagine filter applied to image
     *
     * @param File   $entity
     * @param string $filerName
     * @return string
     */
    public function getFilteredImageUrl(File $entity, $filerName)
    {
        return $this->router->generate(
            'oro_filtered_attachment',
            [
                'id'       => $entity->getId(),
                'filename' => $entity->getOriginalFilename(),
                'filter'   => $filerName
            ]
        );
    }

    /**
     * if in form was clicked delete button and file has not file name - then delete this file record from the db
     *
     * @param File          $entity
     * @param EntityManager $em
     */
    public function checkOnDelete(File $entity, EntityManager $em)
    {
        if ($entity->isEmptyFile() && $entity->getFilename() === null) {
            $em->remove($entity);
        }
    }
}
