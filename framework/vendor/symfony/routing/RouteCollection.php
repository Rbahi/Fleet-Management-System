<?php
namespace Symfony\Component\Routing;

use Symfony\Component\Config\Resource\ResourceInterface;
use Symfony\Component\Routing\Exception\InvalidArgumentException;
use Symfony\Component\Routing\Exception\RouteCircularReferenceException;

/**
 * A RouteCollection represents a set of Route instances.
 *
 * When adding a route at the end of the collection, an existing route
 * with the same name is removed first. So there can only be one route
 * with a given name.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Tobias Schultze <http://tobion.de>
 *
 * @implements \IteratorAggregate<string, Route>
 */
class RouteCollection implements \IteratorAggregate, \Countable
{
    /**
     * @var array<string, Route>
     */
    private $routes = [];

    /**
     * @var array<string, Alias>
     */
    private $aliases = [];

    /**
     * @var array<string, ResourceInterface>
     */
    private $resources = [];

    /**
     * @var array<string, int>
     */
    private $priorities = [];

    public function __clone()
    {
        foreach ($this->routes as $name => $route) {
            $this->routes[$name] = clone $route;
        }

        foreach ($this->aliases as $name => $alias) {
            $this->aliases[$name] = clone $alias;
        }
    }

    /**
     * Gets the current RouteCollection as an Iterator that includes all routes.
     *
     * It implements \IteratorAggregate.
     *
     * @see all()
     *
     * @return \ArrayIterator<string, Route>
     */
    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        return new \ArrayIterator($this->all());
    }

    /**
     * Gets the number of Routes in this collection.
     *
     * @return int
     */
    #[\ReturnTypeWillChange]
    public function count()
    {
        return \count($this->routes);
    }

    /**
     * @param int $priority
     */
    public function add(string $name, Route $route/*, int $priority = 0*/)
    {
        if (\func_num_args() < 3 && __CLASS__ !== static::class && __CLASS__ !== (new \ReflectionMethod($this, __FUNCTION__))->getDeclaringClass()->getName() && !$this instanceof \PHPUnit\Framework\MockObject\MockObject && !$this instanceof \Prophecy\Prophecy\ProphecySubjectInterface && !$this instanceof \Mockery\MockInterface) {
            trigger_deprecation('symfony/routing', '5.1', 'The "%s()" method will have a new "int $priority = 0" argument in version 6.0, not defining it is deprecated.', __METHOD__);
        }

        unset($this->routes[$name], $this->priorities[$name], $this->aliases[$name]);

        $this->routes[$name] = $route;

        if ($priority = 3 <= \func_num_args() ? func_get_arg(2) : 0) {
            $this->priorities[$name] = $priority;
        }
    }

    /**
     * Returns all routes in this collection.
     *
     * @return array<string, Route>
     */
    public function all()
    {
        if ($this->priorities) {
            $priorities = $this->priorities;
            $keysOrder = array_flip(array_keys($this->routes));
            uksort($this->routes, static function ($n1, $n2) use ($priorities, $keysOrder) {
                return (($priorities[$n2] ?? 0) <=> ($priorities[$n1] ?? 0)) ?: ($keysOrder[$n1] <=> $keysOrder[$n2]);
            });
        }

        return $this->routes;
    }

    /**
     * Gets a route by name.
     *
     * @return Route|null
     */
    public function get(string $name)
    {
        $visited = [];
        while (null !== $alias = $this->aliases[$name] ?? null) {
            if (false !== $searchKey = array_search($name, $visited)) {
                $visited[] = $name;

                throw new RouteCircularReferenceException($name, \array_slice($visited, $searchKey));
            }

            if ($alias->isDeprecated()) {
                $deprecation = $alias->getDeprecation($name);

                trigger_deprecation($deprecation['package'], $deprecation['version'], $deprecation['message']);
            }

            $visited[] = $name;
            $name = $alias->getId();
        }

        return $this->routes[$name] ?? null;
    }

    /**
     * Removes a route or an array of routes by name from the collection.
     *
     * @param string|string[] $name The route name or an array of route names
     */
    public function remove($name)
    {
        foreach ((array) $name as $n) {
            unset($this->routes[$n], $this->priorities[$n], $this->aliases[$n]);
        }
    }

    /**
     * Adds a route collection at the end of the current set by appending all
     * routes of the added collection.
     */
    public function addCollection(self $collection)
    {
        // we need to remove all routes with the same names first because just replacing them
        // would not place the new route at the end of the merged array
        foreach ($collection->all() as $name => $route) {
            unset($this->routes[$name], $this->priorities[$name], $this->aliases[$name]);
            $this->routes[$name] = $route;

            if (isset($collection->priorities[$name])) {
                $this->priorities[$name] = $collection->priorities[$name];
            }
        }

        foreach ($collection->getAliases() as $name => $alias) {
            unset($this->routes[$name], $this->priorities[$name], $this->aliases[$name]);

            $this->aliases[$name] = $alias;
        }

        foreach ($collection->getResources() as $resource) {
            $this->addResource($resource);
        }
    }

    /**
     * Adds a prefix to the path of all child routes.
     */
    public function addPrefix(string $prefix, array $defaults = [], array $requirements = [])
    {
        $prefix = trim(trim($prefix), '/');

        if ('' === $prefix) {
            return;
        }

        foreach ($this->routes as $route) {
            $route->setPath('/'.$prefix.$route->getPath());
            $route->addDefaults($defaults);
            $route->addRequirements($requirements);
        }
    }

    /**
     * Adds a prefix to the name of all the routes within in the collection.
     */
    public function addNamePrefix(string $prefix)
    {
        $prefixedRoutes = [];
        $prefixedPriorities = [];
        $prefixedAliases = [];

        foreach ($this->routes as $name => $route) {
            $prefixedRoutes[$prefix.$name] = $route;
            if (null !== $canonicalName = $route->getDefault('_canonical_route')) {
                $route->setDefault('_canonical_route', $prefix.$canonicalName);
            }
            if (isset($this->priorities[$name])) {
                $prefixedPriorities[$prefix.$name] = $this->priorities[$name];
            }
        }

        foreach ($this->aliases as $name => $alias) {
            $prefixedAliases[$prefix.$name] = $alias->withId($prefix.$alias->getId());
        }

        $this->routes = $prefixedRoutes;
        $this->priorities = $prefixedPriorities;
        $this->aliases = $prefixedAliases;
    }

    /**
     * Sets the host pattern on all routes.
     */
    public function setHost(?string $pattern, array $defaults = [], array $requirements = [])
    {
        foreach ($this->routes as $route) {
            $route->setHost($pattern);
            $route->addDefaults($defaults);
            $route->addRequirements($requirements);
        }
    }

    /**
     * Sets a condition on all routes.
     *
     * Existing conditions will be overridden.
     */
    public function setCondition(?string $condition)
    {
        foreach ($this->routes as $route) {
            $route->setCondition($condition);
        }
    }

    /**
     * Adds defaults to all routes.
     *
     * An existing default value under the same name in a route will be overridden.
     */
    public function addDefaults(array $defaults)
    {
        if ($defaults) {
            foreach ($this->routes as $route) {
                $route->addDefaults($defaults);
            }
        }
    }

    /**
     * Adds requirements to all routes.
     *
     * An existing requirement under the same name in a route will be overridden.
     */
    public function addRequirements(array $requirements)
    {
        if ($requirements) {
            foreach ($this->routes as $route) {
                $route->addRequirements($requirements);
            }
        }
    }

    /**
     * Adds options to all routes.
     *
     * An existing option value under the same name in a route will be overridden.
     */
    public function addOptions(array $options)
    {
        if ($options) {
            foreach ($this->routes as $route) {
                $route->addOptions($options);
            }
        }
    }

    /**
     * Sets the schemes (e.g. 'https') all child routes are restricted to.
     *
     * @param string|string[] $schemes The scheme or an array of schemes
     */
    public function setSchemes($schemes)
    {
        foreach ($this->routes as $route) {
            $route->setSchemes($schemes);
        }
    }

    /**
     * Sets the HTTP methods (e.g. 'POST') all child routes are restricted to.
     *
     * @param string|string[] $methods The method or an array of methods
     */
    public function setMethods($methods)
    {
        foreach ($this->routes as $route) {
            $route->setMethods($methods);
        }
    }

    /**
     * Returns an array of resources loaded to build this collection.
     *
     * @return ResourceInterface[]
     */
    public function getResources()
    {
        return array_values($this->resources);
    }

    /**
     * Adds a resource for this collection. If the resource already exists
     * it is not added.
     */
    public function addResource(ResourceInterface $resource)
    {
        $key = (string) $resource;

        if (!isset($this->resources[$key])) {
            $this->resources[$key] = $resource;
        }
    }

    /**
     * Sets an alias for an existing route.
     *
     * @param string $name  The alias to create
     * @param string $alias The route to alias
     *
     * @throws InvalidArgumentException if the alias is for itself
     */
    public function addAlias(string $name, string $alias): Alias
    {
        if ($name === $alias) {
            throw new InvalidArgumentException(sprintf('Route alias "%s" can not reference itself.', $name));
        }

        unset($this->routes[$name], $this->priorities[$name]);

        return $this->aliases[$name] = new Alias($alias);
    }

    /**
     * @return array<string, Alias>
     */
    public function getAliases(): array
    {
        return $this->aliases;
    }

    public function getAlias(string $name): ?Alias
    {
        return $this->aliases[$name] ?? null;
    }
}