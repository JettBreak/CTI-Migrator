<?php

namespace App\Form;

use App\Enum\AppTheme;
use App\Enum\LoadingAnimation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** Administration > Settings: the app-wide settings (App\Service\AppSettings). */
final class AppSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('theme', EnumType::class, [
                'class' => AppTheme::class,
                'label' => 'Theme',
                'expanded' => true,
                'choice_label' => static fn (AppTheme $theme) => $theme->label(),
                'constraints' => [new Assert\NotNull(message: 'Choose a theme.')],
            ])
            ->add('loadingAnimation', EnumType::class, [
                'class' => LoadingAnimation::class,
                'label' => 'Loading animation',
                'expanded' => true,
                'choice_label' => static fn (LoadingAnimation $animation) => $animation->label(),
                'constraints' => [new Assert\NotNull(message: 'Choose a loading animation.')],
            ]);
    }
}
