<?php

namespace CleverAge\EAVManager\ProcessBundle\Task;

use CleverAge\ProcessBundle\Model\IterableTaskInterface;
use CleverAge\ProcessBundle\Model\ProcessState;
use Doctrine\ORM\Internal\Hydration\IterableResult;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Psr\Log\LogLevel;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Allows to iterate over a paged resultset of EAV data
 */
class EAVReaderTask extends AbstractEAVQueryTask implements IterableTaskInterface
{
    /** @var IterableResult */
    protected $iterator;

    /** @var bool */
    protected $closed = false;

    /**
     * {@inheritDoc}
     *
     * @throws \Pagerfanta\Exception\NotIntegerCurrentPageException
     * @throws \Pagerfanta\Exception\OutOfRangeCurrentPageException
     * @throws \Pagerfanta\Exception\LessThan1CurrentPageException
     * @throws \UnexpectedValueException
     * @throws \Sidus\EAVModelBundle\Exception\MissingAttributeException
     * @throws \LogicException
     * @throws \Symfony\Component\OptionsResolver\Exception\ExceptionInterface
     */
    public function execute(ProcessState $state)
    {
        $options = $this->getOptions($state);
        if ($this->closed) {
            if ($options['allow_reset']) {
                $this->closed = false;
                $this->iterator = null;
                $state->log('Reader was closed previously, restarting it', LogLevel::WARNING, $options['family'], $options);
            } else {
                $state->log('Reader was closed previously, stopping the process', LogLevel::ERROR, $options['family'], $options);
                $state->setStopped(true);

                return;
            }
        }

        if (null === $this->iterator) {
            $qb = $this->getQueryBuilder($state);
            $query = $qb->getQuery();

            $this->iterator = $query->iterate();
            $this->iterator->next(); // Move to first element

            // Log the data count
            if ($this->getOption($state, 'log_count')) {
                $pager = new Paginator($query);
                $count = count($pager);
                $state->log("{$count} items found with current query", LogLevel::INFO, $options['family'], $options);
            }
        }

        $result = $this->iterator->current();

        // Handle empty results
        if (false === $result) {
            if ($this->getOption($state, 'log_count')) {
                $state->log('Empty resultset for query, stopping the process', LogLevel::NOTICE, $options['family'], $options);
            }
            $state->setStopped(true);

            return;
        }

        $state->setOutput(reset($result));
    }

    /**
     * Moves the internal pointer to the next element,
     * return true if the task has a next element
     * return false if the task has terminated it's iteration
     *
     * @param ProcessState $state
     *
     * @return bool
     * @throws \LogicException
     */
    public function next(ProcessState $state)
    {
        if (!$this->iterator instanceof IterableResult) {
            throw new \LogicException('No iterator initialized');
        }
        $this->iterator->next();

        $valid = $this->iterator->valid();
        if (!$valid) {
            $this->closed = true;
        }

        return $valid;
    }

    /**
     * {@inheritDoc}
     * @throws \Sidus\EAVModelBundle\Exception\MissingFamilyException
     */
    protected function configureOptions(OptionsResolver $resolver)
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults([
            'allow_reset' => false,   // Allow the reader to reset it's iterator
            'log_count'   => false,   // Log in state history the result count
        ]);
    }
}
