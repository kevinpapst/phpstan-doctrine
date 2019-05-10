<?php declare(strict_types = 1);

namespace PHPStan\Rules\Doctrine\ORM;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\Doctrine\ObjectMetadataResolver;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeUtils;

class DqlRule implements Rule
{

	/** @var ObjectMetadataResolver */
	private $objectMetadataResolver;

	public function __construct(ObjectMetadataResolver $objectMetadataResolver)
	{
		$this->objectMetadataResolver = $objectMetadataResolver;
	}

	public function getNodeType(): string
	{
		return Node\Expr\MethodCall::class;
	}

	/**
	 * @param \PhpParser\Node\Expr\MethodCall $node
	 * @param \PHPStan\Analyser\Scope $scope
	 * @return string[]
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if (!$node->name instanceof Node\Identifier) {
			return [];
		}

		if (count($node->args) === 0) {
			return [];
		}

		$methodName = $node->name->toLowerString();
		if ($methodName !== 'createquery') {
			return [];
		}

		$calledOnType = $scope->getType($node->var);
		$entityManagerInterface = 'Doctrine\ORM\EntityManagerInterface';
		if (!(new ObjectType($entityManagerInterface))->isSuperTypeOf($calledOnType)->yes()) {
			return [];
		}

		$dqls = TypeUtils::getConstantStrings($scope->getType($node->args[0]->value));
		if (count($dqls) === 0) {
			return [];
		}

		$objectManager = $this->objectMetadataResolver->getObjectManager();
		if ($objectManager === null) {
			throw new ShouldNotHappenException('Please provide the "objectManagerLoader" setting for the DQL validation.');
		}
		if (!$objectManager instanceof $entityManagerInterface) {
			return [];
		}

		/** @var \Doctrine\ORM\EntityManagerInterface $objectManager */
		$objectManager = $objectManager;

		$messages = [];
		foreach ($dqls as $dql) {
			$query = $objectManager->createQuery($dql->getValue());
			try {
				$query->getSQL();
			} catch (\Doctrine\ORM\Query\QueryException $e) {
				$messages[] = sprintf('DQL: %s', $e->getMessage());
			}
		}

		return $messages;
	}

}