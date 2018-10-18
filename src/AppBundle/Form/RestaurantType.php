<?php

namespace AppBundle\Form;

use AppBundle\Entity\Restaurant;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RestaurantType extends LocalBusinessType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        parent::buildForm($builder, $options);

        $builder
            ->add('orderingDelayDays', IntegerType::class, [
                'label' => 'localBusiness.form.orderingDelayDays',
                'mapped' => false
            ])
            ->add('orderingDelayHours', IntegerType::class, [
                'label' => 'localBusiness.form.orderingDelayHours',
                'mapped' => false
            ])
            ->add('allowStripeConnect', CheckboxType::class, [
                'label' => 'restaurant.form.allow_stripe_connect.label',
                'mapped' => false,
                'required' => false,
            ]);

        if ($this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            $builder
                ->add('contract', ContractType::class)
                ->add('delete', SubmitType::class, [
                    'label' => 'basics.delete',
                ]);
        }

        $builder->add('deliveryPerimeterExpression', HiddenType::class, ['label' => 'localBusiness.form.deliveryPerimeterExpression',]);

        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) use ($options) {
            $restaurant = $event->getData();
            $form = $event->getForm();
            $orderingDelayMinutes = $restaurant->getOrderingDelayMinutes();
            $orderingDelayDays = $orderingDelayMinutes / (60 * 24);
            $remainder = $orderingDelayMinutes % (60 * 24);
            $orderingDelayHours = $remainder / 60;

            $form->get('orderingDelayHours')->setData($orderingDelayHours);
            $form->get('orderingDelayDays')->setData($orderingDelayDays);

            if (in_array('ROLE_RESTAURANT', $restaurant->getStripeConnectRoles())) {
                $form->get('allowStripeConnect')->setData(true);
            }
        });

        $builder->addEventListener(
            FormEvents::POST_SUBMIT,
            function (FormEvent $event) use ($options) {
                $restaurant = $event->getForm()->getData();
                $orderingDelayDays = $event->getForm()->get('orderingDelayDays')->getData();
                $orderingDelayHours = $event->getForm()->get('orderingDelayHours')->getData();
                $restaurant->setOrderingDelayMinutes($orderingDelayDays * 60 * 24 + $orderingDelayHours * 60);

                $allowStripeConnect = $event->getForm()->get('allowStripeConnect')->getData();
                if ($allowStripeConnect) {
                    $stripeConnectRoles = $restaurant->getStripeConnectRoles();
                    if (!in_array('ROLE_RESTAURANT', $stripeConnectRoles)) {
                        $stripeConnectRoles[] = 'ROLE_RESTAURANT';
                        $restaurant->setStripeConnectRoles($stripeConnectRoles);
                    }
                }
            }
        );

    }

    public function configureOptions(OptionsResolver $resolver)
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults(array(
            'data_class' => Restaurant::class,
        ));
    }
}
