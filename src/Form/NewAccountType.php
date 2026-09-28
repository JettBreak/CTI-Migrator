<?php

namespace App\Form;

use App\Entity\User;
use App\Enum\UserRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** A request for a new account; another administrator approves it before the account exists. */
final class NewAccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'help' => 'Lower-case letters, digits, dots, dashes and underscores. It can never be changed or reused.',
                'attr' => ['maxlength' => 50, 'autocomplete' => 'off', 'spellcheck' => 'false'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Enter a username.'),
                    new Assert\Regex(pattern: User::USERNAME_PATTERN, message: 'Use 3 to 50 lower-case letters, digits, dots, dashes or underscores, starting with a letter or digit.'),
                ],
            ])
            ->add('displayName', TextType::class, [
                'label' => 'Full name',
                'attr' => ['maxlength' => 120, 'autocomplete' => 'off'],
                'constraints' => [new Assert\NotBlank(message: 'Enter the person\'s name.'), new Assert\Length(max: 120)],
            ])
            ->add('role', EnumType::class, [
                'class' => UserRole::class,
                'choice_label' => static fn (UserRole $role) => $role->label(),
                'placeholder' => 'Choose a role',
                'constraints' => [new Assert\NotNull(message: 'Choose a role.')],
            ])
            ->add('reason', TextareaType::class, [
                'help' => 'Why this person needs access, e.g. a ticket or memo reference.',
                'attr' => ['rows' => 2, 'maxlength' => 1000],
                'constraints' => [new Assert\NotBlank(message: 'Give a reason.'), new Assert\Length(max: 1000)],
            ]);
    }
}
