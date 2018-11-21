<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */

namespace Spiral\ORM\Relation;

use Spiral\ORM\Command\CommandInterface;
use Spiral\ORM\Command\ContextualInterface;
use Spiral\ORM\Command\Database\LinkCommand;
use Spiral\ORM\Command\NullCommand;
use Spiral\ORM\DependencyInterface;
use Spiral\ORM\Relation;
use Spiral\ORM\Schema;
use Spiral\ORM\State;

/**
 * Variation of belongs-to relation which provides the ability to be nullable. Relation can be used
 * to create cyclic references. Relation does not trigger store operation of referenced object!
 */
class RefersToRelation extends AbstractRelation implements DependencyInterface
{
    /**
     * @inheritdoc
     */
    public function queueRelation(
        ContextualInterface $command,
        $entity,
        State $state,
        $related,
        $original
    ): CommandInterface {
        $innerKey = $this->define(Relation::INNER_KEY);
        $outerKey = $this->define(Relation::OUTER_KEY);
        $primaryKey = $this->orm->getSchema()->define(get_class($entity), Schema::PRIMARY_KEY);

        // refers-to relation is always nullable (as opposite to belongs-to)
        if (is_null($related)) {
            $command->setContext($innerKey, null);

            return new NullCommand();
        }

        $relState = $this->getState($related);

        // related object exists, we can update key immediately
        if (!empty($relState) && !empty($relState->getKey($outerKey))) {
            $command->setContext($innerKey, $relState->getKey($outerKey));

            return new NullCommand();
        }

        // update the connection between objects once keys are resolved
        $link = new LinkCommand(
            $this->orm->getDatabase($entity),
            $this->orm->getSchema()->define(get_class($entity), Schema::TABLE),
            $this
        );

        $this->promiseWhere($link, $state, $primaryKey, null, $primaryKey);

        // state either not found or key value is not set, subscribe thought the heap
        $this->orm->getHeap()->onUpdate($related, function (State $state) use ($link, $innerKey, $outerKey) {
            if (!empty($value = $state->getKey($outerKey))) {
                $link->setContext($innerKey, $value);
            }
        });

        return $link;
    }
}