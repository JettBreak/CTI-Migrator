<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The super user's credentials and a reason, plus, when setting a lock ("purpose" lock), what to do:
 * lock now, lock after a number of days, or cancel a timed lock that has not started.
 */
final class SystemLockType extends AbstractType
{
    public const LOCK_NOW = 'lock_now';
    public const LOCK_AFTER_DAYS = 'lock_after_days';
    public const CANCEL = 'cancel';
    public const MAX_DAYS = 3650;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'label' => 'Super user',
                'attr' => ['autocomplete' => 'off', 'maxlength' => 180],
                'constraints' => [new Assert\NotBlank(message: 'Enter the super user name.')],
            ])
            ->add('password', PasswordType::class, [
                'label' => 'Password',
                'attr' => ['autocomplete' => 'off', 'maxlength' => 4096],
                'constraints' => [new Assert\NotBlank(message: 'Enter the super user password.')],
            ]);

        if ('lock' === $options['purpose']) {
            $choices = ['Lock now' => self::LOCK_NOW, 'Allow use for a number of days, then lock' => self::LOCK_AFTER_DAYS];
            if ($options['scheduled']) {
                $choices['Cancel the timed lock'] = self::CANCEL;
            }
            $builder
                ->add('action', ChoiceType::class, [
                    'label' => 'Lock',
                    'choices' => $choices,
                    'expanded' => true,
                    'data' => self::LOCK_NOW,
                    'constraints' => [new Assert\NotBlank()],
                ])
                ->add('days', IntegerType::class, [
                    'label' => 'Days of use left',
                    'required' => false,
                    'attr' => ['min' => 1, 'max' => self::MAX_DAYS],
                    'constraints' => [new Assert\Range(min: 1, max: self::MAX_DAYS, notInRangeMessage: 'Choose between {{ min }} and {{ max }} days.')],
                ]);
        }

        $builder->add('reason', TextareaType::class, [
            'label' => 'Reason',
            'attr' => ['maxlength' => 1000, 'rows' => 2],
            'constraints' => [new Assert\NotBlank(message: 'Give a reason; it is kept in the audit trail.'), new Assert\Length(max: 1000)],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['purpose' => 'lock', 'scheduled' => false]);
        $resolver->setAllowedValues('purpose', ['lock', 'unlock']);
        $resolver->setAllowedTypes('scheduled', 'bool');
    }
}
