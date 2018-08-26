<?php

namespace AppBundle\Form;

use AppBundle\Domain\Task\Event;
use AppBundle\Entity\Task;
use AppBundle\Entity\TaskRepository;
use League\Csv\Writer as CsvWriter;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\Extension\Core\Type as FormType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TaskExportType extends AbstractType
{
    private $taskRepository;

    public function __construct(TaskRepository $taskRepository)
    {
        $this->taskRepository = $taskRepository;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($options) {

            $taskExport = $event->getForm()->getData();

            $assignedTasks = $this->taskRepository->findAssigned($taskExport->date);

            $csv = CsvWriter::createFromString('');
            $csv->insertOne([
                '#',
                'type',
                'address.name',
                'address.streetAddress',
                'address.latlng',
                'status',
                'comments',
                'event.DONE.notes',
                'event.FAILED.notes',
                'finishedAt'
            ]);

            $records = [];
            foreach ($assignedTasks as $task) {
                $address = $task->getAddress();
                $finishedAt = '';

                if ($task->hasEvent(Event\TaskDone::messageName())) {
                    $finishedAt = $task
                        ->getLastEvent(Event\TaskDone::messageName())
                        ->getCreatedAt()->format('Y-m-d H:i:s');
                } else if ($task->hasEvent(Event\TaskFailed::messageName())) {
                    $finishedAt = $task
                        ->getLastEvent(Event\TaskFailed::messageName())
                        ->getCreatedAt()->format('Y-m-d H:i:s');
                }

                $records[] = [
                    $task->getId(),
                    $task->getType(),
                    $address->getName(),
                    $address->getStreetAddress(),
                    implode(',', [$address->getGeo()->getLatitude(), $address->getGeo()->getLongitude()]),
                    $task->getStatus(),
                    $task->getComments(),
                    $task->hasEvent(Event\TaskDone::messageName()) ? $task->getLastEvent(Event\TaskDone::messageName())->getData('notes') : '',
                    $task->hasEvent(Event\TaskFailed::messageName()) ? $task->getLastEvent(Event\TaskFailed::messageName())->getData('notes') : '',
                    $finishedAt
                ];
            }
            $csv->insertAll($records);

            $taskExport->csv = $csv->getContent();

            $event->getForm()->setData($taskExport);
        });
    }
}
