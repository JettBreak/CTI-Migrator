<?php

namespace App\Form;

use App\Entity\User;
use App\Validator\PasswordPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

/** The signed-in user's own password change: the current password, then the new one twice. */
final class ChangePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Current password',
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Enter your current password.'),
                    new UserPassword(message: 'This is not your current password.'),
                ],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'The two new passwords do not match.',
                'first_options' => ['label' => 'New password', 'attr' => ['autocomplete' => 'new-password', 'maxlength' => PasswordPolicy::MAX_LENGTH]],
                'second_options' => ['label' => 'Repeat the new password', 'attr' => ['autocomplete' => 'new-password', 'maxlength' => PasswordPolicy::MAX_LENGTH]],
                'constraints' => [
                    new Assert\NotBlank(message: 'Choose a new password.'),
                    new PasswordPolicy($options['user']),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('user');
        $resolver->setAllowedTypes('user', User::class);
    }
}
