<?php

namespace App\Form;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class MappingUploadType extends AbstractType
{
    public function __construct(
        #[Autowire('%app.batch.upload_max_size%')] private readonly string $maxSize,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('file', FileType::class, [
            'label' => 'Replacement mapping file',
            'attr' => ['accept' => '.csv'],
            'constraints' => [
                new Assert\NotNull(message: 'Choose a CSV file to upload.'),
                new Assert\File(maxSize: $this->maxSize),
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
