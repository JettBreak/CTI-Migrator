<?php

namespace App\Form;

use App\Migration\UploadLimit;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class MappingUploadType extends AbstractType
{
    public function __construct(
        private readonly UploadLimit $limit,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $tooLarge = sprintf('The file is too large: this server accepts files up to %s.', $this->limit->label());
        $builder->add('file', FileType::class, [
            'label' => 'Replacement mapping file',
            // The size is checked in the browser too (field-validation controller): a file over the
            // server's post_max_size would otherwise arrive with nothing in it to report on.
            'attr' => ['accept' => '.csv', 'data-max-bytes' => $this->limit->bytes(), 'data-size-message' => $tooLarge],
            'constraints' => [
                new Assert\NotNull(message: 'Choose a CSV file to upload.'),
                new Assert\File(maxSize: $this->limit->bytes(), maxSizeMessage: $tooLarge, uploadIniSizeErrorMessage: $tooLarge),
                // Checked by extension: the server has no fileinfo extension for MIME sniffing,
                // and the parser rejects anything that is not a well-formed mapping CSV anyway.
                new Assert\Callback(static function (mixed $file, ExecutionContextInterface $context): void {
                    if ($file && 'csv' !== strtolower($file->getClientOriginalExtension())) {
                        $context->addViolation('Upload a .csv file.');
                    }
                }),
            ],
        ]);
    }
}
