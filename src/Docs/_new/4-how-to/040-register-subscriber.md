[titleEn]: <>(How to create a subscriber)
[wikiUrl]: <>(../how-to/how-to-create-a-subscriber?category=platform-en/how-to)

This HowTo will cover what you need to know in order to create a subscriber using your plugin.

## Plugin base class

Registering a custom subscriber requires to load a custom `services.xml` file with your plugin.
This is done in your plugins base class by using the `build` method.
Make sure to have a look at the guide about the [plugin base class](../2-platform/4-extending-the-platform/1-plugins/020-plugin-base-class.md) for further information.

```php
<?php declare(strict_types=1);

namespace SubscriberPlugin;

use Shopware\Core\Framework\Plugin;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\Config\FileLocator;

class SubscriberPlugin extends Plugin
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/DependencyInjection/'));
        $loader->load('services.xml');
    }
}
```

Basically, that's it already if you're familiar with [Symfony subscribers](https://symfony.com/doc/current/event_dispatcher.html#creating-an-event-subscriber).
Don't worry, we got you covered here as well.

## Creating the subscriber class

As mentioned above, a subscriber for the Shopware platform looks exactly the same like in Symfony itself.
Therefore, this is how your subscriber could then look like.

```php
<?php declare(strict_types=1);

namespace SubscriberPlugin\Subscriber;

use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Content\Product\ProductEvents;

class MySubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        // Return the events to listen to as array like this:  <event to listen to> => <method to execute>
        return [
            ProductEvents::PRODUCT_LOADED_EVENT => 'onProductsLoaded'
        ];
    }

    public function onProductsLoaded(EntityLoadedEvent $event)
    {
        // Do something
        // E.g. work with the loaded entities: $event->getEntities()
    }
}
```

The subscriber is now listening for the `product.loaded` event to trigger.
Unfortunately, your subscriber is not even loaded yet - this will be done in the previously registered `services.xml` file.

## Introducing your subscriber via services.xml

Registering your subscriber to the Shopware platform is also as simple as it is in Symfony.
You're simply [registering your (subscriber) service](./register-a-service.md) by mentioning it in the `services.xml`.
The only difference to a normal service is, that you need to the `kernel.event_subscriber` tag to your subscriber for it
to be recognized as such.

```xml
<?xml version="1.0" ?>

<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services http://symfony.com/schema/dic/services/services-1.0.xsd">

    <services>
        <service id="SubscriberPlugin\Subscriber\MySubscriber">
            <tag name="kernel.event_subscriber"/>
        </service>
    </services>
</container>
```

That's it, your subscriber service is now automatically loaded at runtime and it should start listening to the mentioned events
to be dispatched.